<?php
defined( 'ABSPATH' ) || exit;
echo TSE_Shortcodes::instance()->render( array(
	'eleicao' => $attributes['eleicao'] ?? 'eleicoes-2026',
	'turno' => $attributes['turno'] ?? 1,
	'cargo' => $attributes['cargo'] ?? '',
	'abrangencia' => $attributes['abrangencia'] ?? 'BR',
	'titulo' => $attributes['titulo'] ?? '',
) );
