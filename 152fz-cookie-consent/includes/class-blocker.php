<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blocker {
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'start' ), 0 );
		add_filter( 'script_loader_tag', array( $this, 'registered' ), 10, 3 );
	}

	public function start(): void {
		if ( ! Frontend::enabled() || is_feed() || is_robots() || is_trackback() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() ) {
			return;
		}
		ob_start( array( $this, 'rewrite' ) );
	}

	public function registered( string $tag, string $handle, string $src ): string {
		if ( ! Frontend::enabled() ) {
			return $tag;
		}
		if ( preg_match( '~^(?:wc-|woocommerce|wc-block|wp-woocommerce)~i', $handle ) || str_contains( $src, '/woocommerce/assets/' ) ) {
			return $tag;
		}
		$data = wp_scripts()->get_data( $handle, 'rcc_category' );
		$category = $data ? sanitize_key( $data ) : Scanner::detect( $src );
		return $category && 'necessary' !== $category ? $this->block_script( $tag, $category ) : $tag;
	}

	private function block_script( string $tag, string $category ): string {
		if ( str_contains( $tag, 'data-rcc-category=' ) ) {
			return $tag;
		}
		do_action( 'rcc_scripts_blocked', $category, $tag );
		// Remove active src/type while retaining inert data attributes. Browser will not execute a text/plain script.
		$tag = preg_replace( '~\s+src\s*=\s*(["\'])(.*?)\1~is', ' data-rcc-src=$1$2$1', $tag );
		$tag = preg_replace( '~\s+type\s*=\s*(["\'])(.*?)\1~is', ' data-rcc-type=$1$2$1', $tag );
		return preg_replace( '~<script\b~i', '<script type="text/plain" data-rcc-category="' . esc_attr( $category ) . '"', $tag, 1 );
	}

	public function rewrite( string $html ): string {
		if ( ! str_contains( strtolower( $html ), '<html' ) ) {
			return $html;
		}
		$html = preg_replace_callback( '~<!--\s*rcc-category\s*=\s*["\']?([a-z0-9_-]+)["\']?\s*-->\s*(<script\b[^>]*>.*?</script\s*>)~is', function ( $m ) {
			$category = sanitize_key( $m[1] );
			return 'necessary' !== $category && isset( Plugin::options()['categories'][ $category ] ) ? $this->block_script( $m[2], $category ) : $m[0];
		}, $html );
		$html = preg_replace_callback( '~<script\b[^>]*>.*?</script\s*>~is', function ( $m ) {
			$tag = $m[0];
			if ( str_contains( $tag, 'data-rcc-category=' ) || str_contains( $tag, 'data-rcc-core=' ) ) {
				return $tag;
			}
			if ( str_contains( $tag, '/woocommerce/assets/' ) ) {
				return $tag;
			}
			$category = null;
			if ( preg_match( '~rcc-category\s*=\s*["\']?([a-z0-9_-]+)~i', $tag, $match ) ) {
				$category = sanitize_key( $match[1] );
			} else {
				// Do not block arbitrary inline scripts on keyword matches: this can break site functionality.
				if ( preg_match( '~^<script\b([^>]*)>~is', $tag, $opening ) ) {
					if ( preg_match( '~\bsrc\s*=~i', $opening[1] ) ) {
						$category = Scanner::detect( $opening[0] );
					} elseif ( preg_match( '~\bid=["\']([^"\']+)-js-(?:before|after)["\']~i', $opening[1], $handle_match ) ) {
						$handle = $handle_match[1];
						$registered = wp_scripts()->registered[ $handle ] ?? null;
						$category = wp_scripts()->get_data( $handle, 'rcc_category' ) ?: ( $registered ? Scanner::detect( (string) $registered->src ) : null );
					} elseif ( preg_match( '~\b(?:id|class)\s*=~i', $opening[1] ) ) {
						// Only inspect the opening tag: do not classify arbitrary inline JavaScript contents.
						$category = Scanner::detect( $opening[0] );
					}
				}
			}
			return $category && isset( Plugin::options()['categories'][ $category ] ) && 'necessary' !== $category ? $this->block_script( $tag, $category ) : $tag;
		}, $html );
		return preg_replace_callback( '~<iframe\b[^>]*>.*?</iframe\s*>~is', static function ( $m ) {
			$category = Scanner::detect( $m[0] );
			if ( ! $category || ! preg_match( '~\bsrc\s*=\s*(["\'])(.*?)\1~is', $m[0], $src ) ) {
				return $m[0];
			}
			$encoded = esc_attr( base64_encode( $m[0] ) );
			return '<div class="rcc-frame" data-rcc-frame="' . $encoded . '" data-rcc-category="' . esc_attr( $category ) . '"><p>' . esc_html__( 'Разрешите cookies, чтобы увидеть содержимое.', '152fz-cookie-consent' ) . '</p><button type="button" data-rcc-open>' . esc_html__( 'Настройки cookies', '152fz-cookie-consent' ) . '</button></div>';
		}, $html );
	}
}
