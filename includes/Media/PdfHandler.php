<?php
/**
 * Handler for PDF files with thumbnail support
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Media
 */

namespace MediaWiki\Media;

use MediaWiki\FileRepo\File\File;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

/**
 * Handler for PDF files
 *
 * @stable to extend
 * @ingroup Media
 */
class PdfHandler extends ImageHandler {

	/**
	 * @inheritDoc
	 */
	public function getParamMap() {
		return [
			'img_width' => 'width',
			'img_page' => 'page'
		];
	}

	/**
	 * @inheritDoc
	 */
	public function validateParam( $name, $value ) {
		if ( $name === 'page' ) {
			return $value > 0;
		}
		return in_array( $name, [ 'width', 'height' ] ) && $value > 0;
	}

	/**
	 * @inheritDoc
	 */
	public function makeParamString( $params ) {
		$page = isset( $params['page'] ) ? 'page' . $params['page'] . '-' : '';
		if ( isset( $params['physicalWidth'] ) ) {
			$width = $params['physicalWidth'];
		} elseif ( isset( $params['width'] ) ) {
			$width = $params['width'];
		} else {
			return false;
		}
		return "{$page}{$width}px";
	}

	/**
	 * @inheritDoc
	 */
	public function parseParamString( $str ) {
		$m = [];
		if ( preg_match( '/^(?:page(\d+)-)?(\d+)px$/', $str, $m ) ) {
			$params = [ 'width' => $m[2] ];
			if ( isset( $m[1] ) ) {
				$params['page'] = (int)$m[1];
			}
			return $params;
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function normaliseParams( $image, &$params ) {
		if ( !isset( $params['width'] ) ) {
			return false;
		}
		if ( !isset( $params['page'] ) ) {
			$params['page'] = 1;
		}
		$params['page'] = (int)$params['page'];
		if ( $params['page'] < 1 ) {
			$params['page'] = 1;
		}
		if ( $params['page'] > $image->pageCount() ) {
			$params['page'] = $image->pageCount();
		}

		$srcWidth = $image->getWidth( $params['page'] );
		$srcHeight = $image->getHeight( $params['page'] );

		if ( !$srcWidth || !$srcHeight ) {
			// Default size if unknown
			$srcWidth = 612;  // Standard PDF 8.5x11 at 72dpi
			$srcHeight = 792;
		}

		if ( isset( $params['height'] ) && $params['height'] !== -1 ) {
			if ( $params['width'] * $srcHeight > $params['height'] * $srcWidth ) {
				$params['width'] = self::fitBoxWidth( $srcWidth, $srcHeight, $params['height'] );
			} else {
				unset( $params['height'] );
			}
		}

		$params['physicalWidth'] = $params['width'];
		$params['physicalHeight'] = File::scaleHeight( $srcWidth, $srcHeight, $params['physicalWidth'] );

		if ( isset( $params['height'] ) && $params['height'] === -1 ) {
			$params['height'] = $params['physicalHeight'];
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getImageSize( $image, $path ) {
		// Try to get PDF dimensions using ImageMagick
		$cmd = sprintf(
			'"%s" "%s[0]" -format "%%w %%h" info: 2>&1',
			$this->getImageMagickCommand(),
			$path
		);
		$output = shell_exec( $cmd );
		if ( $output && preg_match( '/^(\d+)\s+(\d+)$/', trim( $output ), $m ) ) {
			return [ (int)$m[1], (int)$m[2] ];
		}
		return [ 612, 792 ]; // Default 8.5x11
	}

	/**
	 * @inheritDoc
	 */
	public function getSizeAndMetadata( $state, $path ) {
		$size = $this->getImageSize( $state, $path );
		return [
			'width' => $size[0] ?? 612,
			'height' => $size[1] ?? 792,
			'metadata' => [ 'mime' => 'application/pdf', 'pages' => $this->getPageCount( $path ) ]
		];
	}

	/**
	 * Get page count from PDF
	 */
	private function getPageCount( $path ) {
		$cmd = sprintf(
			'"%s" "%s" -format "" info: 2>&1 | find /c /v ""',
			$this->getImageMagickCommand(),
			$path
		);
		$output = shell_exec( $cmd );
		if ( $output ) {
			return (int)trim( $output ) ?: 1;
		}
		return 1;
	}

	/**
	 * @inheritDoc
	 */
	public function pageCount( File $file ) {
		$path = $file->getLocalRefPath();
		if ( !$path ) {
			return 1;
		}
		return $this->getPageCount( $path );
	}

	/**
	 * @inheritDoc
	 */
	public function isMultiPage( $file ) {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function canRender( $file ) {
		return true; // Enable thumbnail generation
	}

	/**
	 * @inheritDoc
	 */
	public function mustRender( $file ) {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function isEnabled() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isFileMetadataValid( $image ) {
		return self::METADATA_GOOD;
	}

	/**
	 * @inheritDoc
	 */
	public function doTransform( $image, $dstPath, $dstUrl, $params, $flags = 0 ) {
		if ( !$this->normaliseParams( $image, $params ) ) {
			return new TransformParameterError( $params );
		}

		$page = $params['page'] ?? 1;
		$width = $params['physicalWidth'];
		$height = $params['physicalHeight'];

		$srcPath = $image->getLocalRefPath();
		if ( !$srcPath ) {
			return new MediaTransformError( 'pdf_source_not_found', $dstPath, $width, $height );
		}

		// Build ImageMagick 7 command for PDF -> PNG conversion
		// IM7 syntax: magick input.pdf output.png (no 'convert' keyword)
		$cmd = sprintf(
			'"%s" "%s[%d]" -resize %dx%d -background white -flatten "%s" 2>&1',
			$this->getImageMagickCommand(),
			$srcPath,
			$page - 1, // ImageMagick uses 0-based index
			$width,
			$height,
			$dstPath
		);

		wfDebug( __METHOD__ . ": Running: $cmd" );

		$output = shell_exec( $cmd );

		if ( !file_exists( $dstPath ) || filesize( $dstPath ) === 0 ) {
			wfDebugLog( 'thumbnail', "PDF thumbnail failed: $cmd\nOutput: $output" );
			return new MediaTransformError( 'pdf_thumbnail_failed', $dstPath, $width, $height, $output );
		}

		return new ThumbnailImage( $image, $dstUrl, $dstPath, $params );
	}

	/**
	 * Get ImageMagick command path from config
	 */
	private function getImageMagickCommand() {
		$command = MediaWikiServices::getInstance()->getMainConfig()
			->get( MainConfigNames::ImageMagickConvertCommand );
		return $command ?: 'magick';
	}

	/**
	 * @inheritDoc
	 */
	public function getThumbType( $ext, $mime, $params = null ) {
		return [ 'png', 'image/png' ];
	}

	/**
	 * @inheritDoc
	 */
	public function isVectorized( $file ) {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getCommonMetaArray( File $file ) {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function getMetadataType( $image ) {
		return 'pdf';
	}
}
