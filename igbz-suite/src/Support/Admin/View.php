<?php
namespace IGBZ\Suite\Support\Admin;

use IGBZ\Suite\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny render helpers shared by every admin screen. Deliberately no template engine: WordPress
 * admin markup plus escaping helpers keeps the plugin dependency-free.
 */
final class View {

	public static function open( string $title, string $description = '' ): void {
		echo '<div class="wrap igbz-wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	public static function close(): void {
		echo '</div>';
	}

	public static function notice( string $message, string $type = 'success' ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/** @param array<string,string> $tabs slug => label */
	public static function tabs( array $tabs, string $current, string $page ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( Menu::url( $page, [ 'tab' => $slug ] ) ),
				esc_attr( $slug === $current ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	/**
	 * Render one settings row.
	 *
	 * @param array{key:string,label:string,type?:string,options?:array<string,string>,help?:string,placeholder?:string,min?:int,max?:int,step?:string} $field
	 */
	public static function field( array $field, mixed $value ): void {
		$type = $field['type'] ?? 'text';
		$id   = 'igbz_' . str_replace( '.', '_', $field['key'] );
		$name = 'igbz[' . $field['key'] . ']';

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="hidden" name="%1$s" value="0" /><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $name ),
					esc_attr( $id ),
					checked( (bool) $value, true, false ),
					esc_html( $field['help'] ?? '' )
				);
				echo '</td></tr>';
				return;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( (array) ( $field['options'] ?? [] ) as $option_value => $option_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( (string) $option_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" class="large-text code">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text" min="%4$s" max="%5$s" step="%6$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $field['min'] ?? 0 ) ),
					esc_attr( (string) ( $field['max'] ?? 999999999 ) ),
					esc_attr( (string) ( $field['step'] ?? '1' ) )
				);
				break;

			case 'password':
				printf(
					'<input type="text" autocomplete="off" id="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] ?? '' )
				);
				echo '<p class="description">' . esc_html__( 'Stored encrypted. Leave the masked value untouched to keep the current secret.', 'igbz-suite' ) . '</p>';
				break;

			case 'readonly':
				printf(
					'<input type="text" readonly onfocus="this.select()" id="%1$s" value="%2$s" class="large-text code" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" placeholder="%5$s" />',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( $field['placeholder'] ?? '' )
				);
		}

		if ( ! empty( $field['help'] ) ) {
			echo '<p class="description">' . wp_kses_post( $field['help'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string,string>                $columns key => label
	 * @param array<int,array<string,mixed>>      $rows
	 * @param callable|null                       $cell    fn(array $row, string $key): string (already escaped)
	 */
	public static function table( array $columns, array $rows, ?callable $cell = null, string $empty = '' ): void {
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			printf(
				'<tr><td colspan="%1$d">%2$s</td></tr>',
				count( $columns ),
				esc_html( '' !== $empty ? $empty : __( 'Nothing here yet.', 'igbz-suite' ) )
			);
		}

		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_keys( $columns ) as $key ) {
				if ( $cell ) {
					echo '<td>' . wp_kses_post( $cell( $row, $key ) ) . '</td>';
					continue;
				}
				echo '<td>' . esc_html( (string) ( $row[ $key ] ?? '' ) ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public static function status_pill( string $status ): string {
		// Phase 71: WCAG AA — white on these darker tints measures 5.2:1–6.9:1;
		// the previous saturated chips (white on #00a32a/#dba617) sat at 3.3:1/2.2:1.
		$colors = [
			'ok'    => '#007117',
			'warn'  => '#8a6800',
			'error' => '#b32d2e',
		];
		$color = $colors[ $status ] ?? '#646970';

		return sprintf(
			'<span style="display:inline-block;min-width:64px;text-align:center;padding:2px 8px;border-radius:9px;color:#fff;background:%1$s">%2$s</span>',
			esc_attr( $color ),
			esc_html( strtoupper( $status ) )
		);
	}

	public static function money( float $amount ): string {
		return function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : number_format_i18n( $amount, 0 );
	}

	public static function mask(): string {
		return Crypto::MASK;
	}

	/** Verify a screen nonce or die. */
	public static function check_nonce( string $action ): void {
		check_admin_referer( $action );
	}

	/** Pagination links for a simple offset list. */
	public static function pagination( int $total, int $per_page, int $current, string $page, array $args = [] ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				[
					'base'      => Menu::url( $page, $args + [ 'paged' => '%#%' ] ),
					'format'    => '',
					'current'   => max( 1, $current ),
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				]
			) ?? ''
		);
		echo '</div></div>';
	}
}
