<?php
namespace RCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scanner {
	/** Domains and literal code signatures. Rules are intentionally conservative to avoid breaking site functions. */
	public static function rules(): array {
		return apply_filters( 'rcc_tracker_rules', array(
			'analytics' => array( 'google-analytics.com', 'gtag/js', 'analytics.js', 'ga.js', 'google.com/analytics', 'mc.yandex.ru', 'metrika/yandex', 'hotjar.com', 'clarity.ms', 'matomo.php', 'plausible.io/js/', 'mixpanel.com', 'segment.com/analytics', 'amplitude.com', 'heap.io', 'fullstory.com', 'newrelic.com', 'stats.wp.com', 'pixel.wp.com', 'adobe.com/analytics', 'omtrdc.net', '2o7.net' ),
			'marketing' => array( 'googletagmanager.com', 'facebook.net/en_us/fbevents', 'connect.facebook.net', 'facebook.com/tr', 'tiktok.com/i18n/pixel', 'analytics.tiktok.com', 'ads-twitter.com', 'static.ads-twitter.com', 'platform.twitter.com/widgets', 'snap.licdn.com', 'linkedin.com/px', 'ct.pinterest.com', 'pintrk', 'criteo.com', 'criteo.net', 'doubleclick.net', 'googleadservices.com', 'googlesyndication.com', 'google.com/pagead', 'vk.com/rtrg', 'vk.com/js/api/openapi', 'top.mail.ru', 'mytarget', 'ads.yandex.ru', 'an.yandex.ru', 'mc.yandex.ru/ym', 'bing.com/bat.js', 'bat.bing.com', 'taboola.com', 'outbrain.com', 'redditstatic.com/ads', 'adroll.com', 'quantserve.com' ),
			'functional' => array( 'youtube.com/embed/', 'youtube-nocookie.com/embed/', 'player.vimeo.com', 'google.com/maps/embed', 'yandex.ru/map-widget', 'api-maps.yandex.ru' ),
		) );
	}

	public static function detect( string $html ): ?string {
		$options = Plugin::options();
		foreach ( explode( "\n", (string) $options['rules'] ) as $line ) {
			$parts = explode( '|', trim( $line ), 2 );
			if ( 2 === count( $parts ) && isset( $options['categories'][ sanitize_key( $parts[0] ) ] ) && '' !== trim( $parts[1] ) && false !== stripos( $html, trim( $parts[1] ) ) ) {
				return sanitize_key( $parts[0] );
			}
		}
		foreach ( self::rules() as $category => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( false !== stripos( $html, $pattern ) ) {
					return $category;
				}
			}
		}
		return null;
	}

	/** Inspect only same-site public URLs; no arbitrary URLs or SSRF. */
	public static function scan( array $ids ): array {
		$urls = array( home_url( '/' ) );
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			if ( get_post_status( (int) $id ) === 'publish' ) {
				$urls[] = get_permalink( (int) $id );
			}
		}
		$found = array();
		foreach ( $urls as $url ) {
			$response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'limit_response_size' => 1024 * 1024 ) );
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$html = wp_remote_retrieve_body( $response );
			preg_match_all( '~<(script|iframe)\b[^>]*>(?:.*?)</\1>~is', $html, $matches );
			foreach ( $matches[0] as $tag ) {
				$category = self::detect( $tag );
				if ( $category ) {
					$found[] = array( 'url' => $url, 'category' => $category, 'snippet' => substr( wp_strip_all_tags( $tag ), 0, 120 ) ?: substr( $tag, 0, 120 ) );
				}
			}
			// HTTP response headers expose Set-Cookie, but JS-set cookies and localStorage need a real browser audit.
			$headers = wp_remote_retrieve_headers( $response );
			if ( isset( $headers['set-cookie'] ) ) {
				foreach ( (array) $headers['set-cookie'] as $cookie ) {
					$found[] = array( 'url' => $url, 'category' => 'server cookie', 'snippet' => strtok( (string) $cookie, '=' ) );
				}
			}
		}
		return $found;
	}
}
