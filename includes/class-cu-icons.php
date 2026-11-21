<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bibliothèque d'icônes SVG "inline" utilisée dans toute l'interface
 * d'administration (menu, en-têtes de page, badges, boutons). Un seul
 * jeu d'icônes cohérent pour garder un style "ultra" homogène.
 *
 * Toutes les icônes sont dessinées en `currentColor` (24x24, stroke 1.8)
 * afin de prendre automatiquement la couleur du texte qui les entoure.
 */
class CU_Icons {

	public static function get( $name, $class = 'cu-icon' ) {
		$paths = self::paths();
		$path  = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['bolt'];
		return sprintf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
			esc_attr( $class ),
			$path
		);
	}

	public static function menu_icon_data_uri() {
		// Icône de menu WordPress : bouclier plein avec coche évidée (fill-rule evenodd), recolorée par WordPress.
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff"><path fill-rule="evenodd" d="M12 2 3 6v6c0 5.25 3.84 9.74 9 11 5.16-1.26 9-5.75 9-11V6l-9-4zm-1.4 13.6L7 12l1.4-1.4 2.2 2.2 5-5L17 9.2l-6.4 6.4z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private static function paths() {
		return array(
			'bolt'        => '<path d="M13 2 3 14h7l-1 8 11-14h-7l0-6z"/>',
			'chart'       => '<path d="M4 19V9"/><path d="M10 19V5"/><path d="M16 19v-7"/><path d="M22 19H2"/>',
			'shield'      => '<path d="M12 3l8 3v6c0 4.97-3.5 8.5-8 10-4.5-1.5-8-5.03-8-10V6l8-3z"/>',
			'shield-check' => '<path d="M12 3l8 3v6c0 4.97-3.5 8.5-8 10-4.5-1.5-8-5.03-8-10V6l8-3z"/><path d="m9 12 2 2 4-4"/>',
			'search'      => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
			'gear'        => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z"/>',
			'list'        => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/>',
			'pulse'       => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
			'globe'       => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>',
			'ban'         => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
			'plus-circle' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v8"/><path d="M8 12h8"/>',
			'refresh'     => '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
			'download'    => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
			'upload'      => '<path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14"/>',
			'folder'      => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
			'sparkles'    => '<path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><path d="m6 6 2 2"/><path d="m16 16 2 2"/><path d="m6 18 2-2"/><path d="m16 8 2-2"/>',
			'trending-up' => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
			'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
			'flag'        => '<path d="M4 3v18"/><path d="M4 4h13l-2 4 2 4H4"/>',
			'link'        => '<path d="M9 17H7a5 5 0 1 1 0-10h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/>',
			'bug'         => '<rect x="8" y="6" width="8" height="12" rx="4"/><path d="M12 6V4"/><path d="m5 9 3 1"/><path d="m19 9-3 1"/><path d="m5 17 3-1"/><path d="m19 17-3-1"/><path d="M8 10h8"/>',
			'cart'        => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.2a2 2 0 0 0 2-1.6L21 7H6"/>',
			'file-text'   => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/>',
			'save'        => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3"/><path d="M8 21v-8h8v8"/>',
			'trash'       => '<path d="M4 7h16"/><path d="M6 7v13a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7"/><path d="M9 7V4h6v3"/>',
			'eye'         => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
			'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/>',
			'key'         => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2"/><path d="m16 6 2 2"/><path d="m13.5 8.5 2 2"/>',
			'rocket'      => '<path d="M5 13c-1.5.5-3 2-3 5 3 0 4.5-1.5 5-3"/><path d="M14 4c3-1 6-1 6-1s0 3-1 6c-1.2 3.6-4 6.3-7 8l-3-3c1.7-3 4.4-5.8 8-7z"/><circle cx="15" cy="9" r="1.4"/>',
			'send'        => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>',
			'radar'       => '<path d="M20.2 8A9 9 0 1 0 21 12"/><path d="M12 12 21 3"/><path d="M12 3v0"/><circle cx="12" cy="12" r="4"/>',
			'copy'        => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			'database'    => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
			'cyber-logo'  => '<path d="M12 2 3 7v6c0 5 4 8.5 9 9 5-.5 9-4 9-9V7l-9-5z"/><path d="M9 12l2 2 4-4"/>',
			'check'       => '<path d="M5 12l5 5 9-10"/>',
			'alert'       => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'wrench'      => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
			'message'     => '<path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 21l2-5.6A8.4 8.4 0 1 1 21 11.5z"/><path d="M8.5 11.5h.01"/><path d="M12 11.5h.01"/><path d="M15.5 11.5h.01"/>',
		);
	}
}
