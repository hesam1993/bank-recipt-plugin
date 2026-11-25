<?php
namespace C2CReceipts;

defined( 'ABSPATH' ) || exit;

final class Storage {

	public static function directory(): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			throw new \RuntimeException( esc_html( __( 'The WordPress uploads directory is unavailable. Check the normal WordPress media upload settings.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$directory = rtrim( $uploads['basedir'], '/\\' ) . '/c2c-receipts';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new \RuntimeException( esc_html( __( 'WordPress cannot create its receipt upload folder. Check the normal uploads directory permissions.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$base = realpath( $uploads['basedir'] );
		$real = realpath( $directory );
		if ( ! $base || ! $real || ! str_starts_with( $real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) || ! wp_is_writable( $real ) || ! is_readable( $real ) ) {
			throw new \RuntimeException( esc_html( __( 'The receipt upload folder must be readable and writable inside the WordPress uploads directory.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return $real;
	}

	/** Old plaintext receipts remain outside the web root; never migrate plaintext into uploads. */
	public static function legacy_directory(): string {
		$path = defined( 'C2C_RECEIPTS_PRIVATE_DIR' ) ? C2C_RECEIPTS_PRIVATE_DIR : ( Support::settings()['private_dir'] ?? '' );
		if ( ! is_string( $path ) || '' === trim( $path ) ) {
			throw new \RuntimeException( esc_html( __( 'Private receipt directory is not configured. On shared hosting, create a folder outside public_html and enter its absolute path below.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		// An expected open_basedir failure must not leak warnings into AJAX/Store API JSON.
		$real = @realpath( $path );
		$roots = array_filter( array( realpath( ABSPATH ), realpath( Support::server( 'DOCUMENT_ROOT' ) ?: ABSPATH ) ) );
		if ( ! $real || ! is_dir( $real ) ) {
			throw new \RuntimeException( esc_html( __( 'The private receipt folder does not exist or PHP cannot access it. Check the absolute path and hosting open_basedir restrictions.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		if ( ! wp_is_writable( $real ) || ! is_readable( $real ) ) {
			throw new \RuntimeException( esc_html( __( 'PHP cannot read and write the private receipt folder. Ask your host to check its owner and permissions.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$permissions = @fileperms( $real );
		if ( DIRECTORY_SEPARATOR === '/' && ( false === $permissions || ( $permissions & 0077 ) !== 0 ) ) {
			throw new \RuntimeException( esc_html( __( 'The private receipt folder must have permissions 0700, with no group or public access.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		foreach ( $roots as $root ) {
			if ( $real === $root || str_starts_with( $real . DIRECTORY_SEPARATOR, rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
				throw new \RuntimeException( esc_html( __( 'The receipt directory must be outside every public web root.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
		}
		return $real;
	}

	public static function problems(): array {
		$problems = array();
		try {
			self::directory();
		} catch ( \RuntimeException $e ) {
			$problems[] = $e->getMessage();
		}
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) || ! function_exists( 'gd_info' ) || ! ( gd_info()['JPEG Support'] ?? false ) ) {
			$problems[] = __( 'PHP GD with JPEG support is unavailable. Enable it in your hosting PHP extensions or ask your host.', 'card-to-card-receipts-for-woocommerce' );
		}
		if ( ! Crypto::available() ) {
			$problems[] = __( 'PHP OpenSSL with AES-256-GCM support is required for protected receipt uploads.', 'card-to-card-receipts-for-woocommerce' );
		}
		return $problems;
	}

	public static function ready(): bool {
		return array() === self::problems();
	}

	public static function path( string $name ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}\.(?:jpg|c2c)$/D', $name ) ) {
			throw new \RuntimeException( esc_html( __( 'Invalid receipt file.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$path = ( str_ends_with( $name, '.jpg' ) ? self::legacy_directory() : self::directory() ) . DIRECTORY_SEPARATOR . $name;
		if ( is_link( $path ) ) {
			throw new \RuntimeException( esc_html( __( 'Invalid receipt file.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return $path;
	}

	public static function read( string $name ): string {
		$path = self::path( $name );
		if ( ! is_readable( $path ) || filesize( $path ) > 32 * MB_IN_BYTES ) {
			throw new \RuntimeException( esc_html( __( 'Receipt unavailable.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$bytes = file_get_contents( $path );
		if ( false === $bytes ) {
			throw new \RuntimeException( esc_html( __( 'Receipt unavailable.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return str_ends_with( $name, '.c2c' ) ? Crypto::open( $bytes, $name ) : $bytes;
	}

	private static function persist( string $image ): array {
		$name = bin2hex( random_bytes( 32 ) ) . '.c2c';
		$bytes = Crypto::seal( $image, $name );
		$directory = self::directory();
		// Use the normal WordPress upload writer and its filesystem permissions. Only ciphertext reaches it.
		$upload_filter = static function ( array $uploads ) use ( $directory ): array {
			$uploads['subdir'] = '/c2c-receipts';
			$uploads['path'] = $directory;
			$uploads['url'] = rtrim( $uploads['baseurl'], '/' ) . '/c2c-receipts';
			return $uploads;
		};
		$mime_filter = static function ( array $mimes ): array {
			$mimes['c2c'] = 'application/octet-stream';
			return $mimes;
		};
		add_filter( 'upload_dir', $upload_filter );
		add_filter( 'upload_mimes', $mime_filter );
		try {
			$saved = wp_upload_bits( $name, null, $bytes );
		} finally {
			remove_filter( 'upload_dir', $upload_filter );
			remove_filter( 'upload_mimes', $mime_filter );
		}
		if ( ! empty( $saved['error'] ) || empty( $saved['file'] ) ) {
			throw new \RuntimeException( esc_html( __( 'WordPress could not save the protected receipt.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		if ( realpath( $saved['file'] ) !== realpath( self::path( $name ) ) ) {
			throw new \RuntimeException( esc_html( __( 'Another upload handler changed the receipt location. Check your upload or media offloading plugin.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		return array( 'filename' => $name, 'bytes' => strlen( $bytes ) );
	}

	/** Decode into a new true-color image: uploaded metadata and original bytes are never stored. */
	public static function save( array $file ): array {
		if ( ! self::ready() ) {
			throw new \RuntimeException( esc_html( __( 'Private receipt storage is unavailable. Please contact the store.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$limit = min( wp_max_upload_size(), max( 1, min( 10, (int) ( Support::settings()['max_mb'] ?? 5 ) ) ) * MB_IN_BYTES );
		$tmp = $file['tmp_name'] ?? '';
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $tmp ) || ! is_file( $tmp ) || filesize( $tmp ) < 1 || filesize( $tmp ) > $limit ) {
			throw new \RuntimeException( esc_html( __( 'Upload failed or the image exceeds the allowed size.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$types = array( 'image/jpeg' => array( 'jpg', 'jpeg' ), 'image/png' => array( 'png' ), 'image/webp' => array( 'webp' ) );
		$info = @getimagesize( $tmp );
		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( ! $info || ! isset( $types[ $info['mime'] ] ) || ! in_array( $ext, $types[ $info['mime'] ], true ) || $info[0] < 1 || $info[1] < 1 || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 12000000 ) {
			throw new \RuntimeException( esc_html( __( 'Use a valid JPEG, PNG or WebP image, at most 6000 pixels per side and 12 megapixels.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		// Bound allocation before decoding; retain room for WordPress and the encoder.
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 && memory_get_usage( true ) + $info[0] * $info[1] * 12 + 16 * MB_IN_BYTES > $memory_limit ) {
			throw new \RuntimeException( esc_html( __( 'This image needs too much memory. Please upload a smaller image.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$image = @imagecreatefromstring( file_get_contents( $tmp ) );
		if ( false === $image ) {
			throw new \RuntimeException( esc_html( __( 'The image could not be decoded.', 'card-to-card-receipts-for-woocommerce' ) ) );
		}
		$clean = imagecreatetruecolor( $info[0], $info[1] );
		imagefill( $clean, 0, 0, imagecolorallocate( $clean, 255, 255, 255 ) );
		imagecopy( $clean, $image, 0, 0, 0, 0, $info[0], $info[1] );
		$level = ob_get_level();
		try {
			ob_start();
			if ( ! imagejpeg( $clean, null, 92 ) ) {
				throw new \RuntimeException( esc_html( __( 'The receipt could not be saved privately.', 'card-to-card-receipts-for-woocommerce' ) ) );
			}
			$bytes = ob_get_clean();
			return self::persist( $bytes );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			imagedestroy( $image );
			imagedestroy( $clean );
		}
	}

	public static function delete( string $name ): bool {
		if ( '' === $name ) {
			return true;
		}
		$path = self::path( $name );
		if ( ! is_file( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! is_file( $path );
	}
}
