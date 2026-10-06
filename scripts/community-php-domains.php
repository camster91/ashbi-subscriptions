<?php
/** Return byte edits for literal WordPress translation-domain arguments only. */
$source = stream_get_contents( STDIN );
$raw    = token_get_all( $source, TOKEN_PARSE );
$tokens = array();
$offset = 0;
foreach ( $raw as $token ) {
	$text = is_array( $token ) ? $token[1] : $token;
	if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
		$tokens[] = array( is_array( $token ) ? $token[0] : null, $text, $offset );
	}
	$offset += strlen( $text );
}
$domains = array(
	'__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1,
	'esc_attr__' => 1, 'esc_attr_e' => 1, '_x' => 2, '_ex' => 2,
	'esc_html_x' => 2, 'esc_attr_x' => 2, '_n' => 3, '_nx' => 4,
	'_n_noop' => 2, '_nx_noop' => 3, 'translate' => 1,
	'translate_with_gettext_context' => 2, 'wp_set_script_translations' => 1,
	'load_plugin_textdomain' => 0, 'load_textdomain' => 0,
);
$edits = array();
foreach ( $tokens as $i => $token ) {
	$name = strtolower( ltrim( $token[1], '\\' ) );
	if ( ! isset( $domains[ $name ] ) || '(' !== ( $tokens[ $i + 1 ][1] ?? '' ) ) {
		continue;
	}
	if ( in_array( $tokens[ $i - 1 ][0] ?? null, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION ), true ) ) {
		continue;
	}
	$args = array();
	$arg = array();
	$depth = 0;
	for ( $j = $i + 2; isset( $tokens[ $j ] ); ++$j ) {
		$t = $tokens[ $j ];
		if ( ')' === $t[1] && 0 === $depth ) {
			$args[] = $arg;
			break;
		}
		if ( ',' === $t[1] && 0 === $depth ) {
			$args[] = $arg;
			$arg = array();
			continue;
		}
		if ( in_array( $t[1], array( '(', '[', '{' ), true ) ) {
			++$depth;
		} elseif ( in_array( $t[1], array( ')', ']', '}' ), true ) ) {
			--$depth;
		}
		$arg[] = $t;
	}
	$domain = $args[ $domains[ $name ] ] ?? array();
	foreach ( $domain as $t ) {
		if ( T_CONSTANT_ENCAPSED_STRING === $t[0] && in_array( $t[1], array( "'subscription'", '"subscription"' ), true ) ) {
			if ( 1 !== count( $domain ) ) {
				throw new RuntimeException( 'Ambiguous composite translation domain: ' . $name );
			}
			$edits[] = array( $t[2], $t[2] + strlen( $t[1] ), $t[1][0] . 'ashbi-subscriptions' . $t[1][0] );
		}
	}
}
echo json_encode( $edits, JSON_THROW_ON_ERROR );
