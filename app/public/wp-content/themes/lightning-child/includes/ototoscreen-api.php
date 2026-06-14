<?php
/**
 * OtotoScreen — 外部API連携関数
 * TMDb / Claude / gpt-image-1 / WordPress の各処理をまとめた関数群
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================
// TMDb API — 映画検索
// =============================================

function ototoscreen_tmdb_search( $query ) {
	$response = wp_remote_get( 'https://api.themoviedb.org/3/search/movie', [
		'timeout' => 15,
		'body'    => [
			'api_key'  => OTOTOSCREEN_TMDB_API_KEY,
			'query'    => $query,
			'language' => 'ja-JP',
		],
	] );

	if ( is_wp_error( $response ) ) return [];

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return array_slice( $body['results'] ?? [], 0, 8 ); // 最大8件
}

// =============================================
// ポスター画像から2色を抽出する
// =============================================

function ototoscreen_extract_colors( $poster_url ) {
	if ( ! $poster_url ) return 'warm beige and soft gray';

	$response = wp_remote_get( $poster_url, [ 'timeout' => 15 ] );
	if ( is_wp_error( $response ) ) return 'warm beige and soft gray';

	if ( ! function_exists( 'imagecreatefromstring' ) ) return 'warm beige and soft gray';

	$image = @imagecreatefromstring( wp_remote_retrieve_body( $response ) );
	if ( ! $image ) return 'warm beige and soft gray';

	// 50×75 に縮小して高速処理
	$thumb = imagecreatetruecolor( 50, 75 );
	imagecopyresampled( $thumb, $image, 0, 0, 0, 0, 50, 75, imagesx( $image ), imagesy( $image ) );
	imagedestroy( $image );

	// 全ピクセルを色名バケツに仕分ける
	$buckets = [];
	for ( $x = 0; $x < 50; $x++ ) {
		for ( $y = 0; $y < 75; $y++ ) {
			$rgb  = imagecolorat( $thumb, $x, $y );
			$name = ototoscreen_describe_color(
				( $rgb >> 16 ) & 0xFF,
				( $rgb >> 8 )  & 0xFF,
				  $rgb         & 0xFF
			);
			$buckets[ $name ] = ( $buckets[ $name ] ?? 0 ) + 1;
		}
	}
	imagedestroy( $thumb );

	arsort( $buckets );
	$top = array_keys( $buckets );

	return ( $top[0] ?? 'very dark charcoal' ) . ' and ' . ( $top[1] ?? 'soft light cream' );
}

function ototoscreen_describe_color( $r, $g, $b ) {
	$brightness = ( $r + $g + $b ) / 3;
	$max        = max( $r, $g, $b );

	if ( $brightness < 60  ) return 'very dark charcoal';
	if ( $brightness > 200 ) return 'soft light cream';
	if ( $max === $r && $r > $g + 30 && $r > $b + 30 )
		return $brightness < 120 ? 'deep warm red' : 'warm terracotta';
	if ( $max === $g && $g > $r + 30 && $g > $b + 30 )
		return $brightness < 120 ? 'deep forest green' : 'soft sage green';
	if ( $max === $b && $b > $r + 30 && $b > $g + 30 )
		return $brightness < 120 ? 'deep navy blue' : 'soft steel blue';
	if ( $r > 150 && $g > 120 && $b < 100 )
		return 'warm golden amber';
	return 'muted slate gray';
}

// =============================================
// Claude API — テキスト生成
// =============================================

function ototoscreen_claude_request( $prompt, $max_tokens = 1024 ) {
	$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
		'timeout' => 60,
		'headers' => [
			'x-api-key'         => OTOTOSCREEN_ANTHROPIC_API_KEY,
			'anthropic-version' => '2023-06-01',
			'content-type'      => 'application/json',
		],
		'body' => wp_json_encode( [
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => $max_tokens,
			'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
		] ),
	] );

	if ( is_wp_error( $response ) ) return $response;

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! empty( $body['error'] ) ) {
		return new WP_Error( 'claude_error', $body['error']['message'] );
	}

	return $body['content'][0]['text'] ?? '';
}

function ototoscreen_generate_description( $title, $overview, $release_date ) {
	$prompt = "あなたは「音楽にまつわる映画」を紹介する日本語ライターです。
以下の映画情報をもとに、読者が「観てみたい」と思えるようなオリジナルの紹介文を300文字程度で書いてください。

【映画タイトル】{$title}
【公開日】{$release_date}
【あらすじ（参考）】{$overview}

条件：
- TMDbのあらすじをそのままコピーしない（オリジナルの文章にする）
- 音楽が映画の中でどんな役割を果たしているかに触れる
- 読みやすい日本語で、一般の映画ファンに向けて書く
- 紹介文だけを出力する（タイトルや見出しは不要）";

	return ototoscreen_claude_request( $prompt, 1024 );
}

function ototoscreen_generate_scene( $title, $description ) {
	$prompt = "The following is a Japanese movie description.
Suggest one simple scene for a minimalist line art illustration that captures the essence of this movie.
Write ONLY the scene description in English, in 15 words or less.
Focus on a single subject (person, instrument, or symbolic object).

Movie title: {$title}
Description: {$description}";

	return ototoscreen_claude_request( $prompt, 200 );
}

// =============================================
// OpenAI API — gpt-image-1 でイラスト生成
// =============================================

function ototoscreen_generate_illustration( $scene, $colors ) {
	$style  = "one continuous line drawing, single line art style, minimalist black thin line on white background, subtle muted color accent shapes in {$colors}, elegant and clean, no color fill, white background, ";
	$prompt = $style . trim( $scene );

	$response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
		'timeout' => 120,
		'headers' => [
			'Authorization' => 'Bearer ' . OTOTOSCREEN_OPENAI_API_KEY,
			'Content-Type'  => 'application/json',
		],
		'body' => wp_json_encode( [
			'model'   => 'gpt-image-1',
			'prompt'  => $prompt,
			'size'    => '1024x1024',
			'quality' => 'low',
			'n'       => 1,
		] ),
	] );

	if ( is_wp_error( $response ) ) return $response;

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! empty( $body['error'] ) ) {
		return new WP_Error( 'openai_error', $body['error']['message'] );
	}

	$b64 = $body['data'][0]['b64_json'] ?? null;
	if ( ! $b64 ) return new WP_Error( 'openai_error', '画像データを取得できませんでした。' );

	return base64_decode( $b64 );
}

// =============================================
// WordPress — 画像アップロード・記事作成
// =============================================

function ototoscreen_upload_illustration( $image_bytes, $movie_id ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$tmp = wp_tempnam( "movie-{$movie_id}.png" );
	file_put_contents( $tmp, $image_bytes );

	$file_array = [
		'name'     => "movie-{$movie_id}-illustration.png",
		'tmp_name' => $tmp,
		'type'     => 'image/png',
		'error'    => 0,
		'size'     => strlen( $image_bytes ),
	];

	$media_id = media_handle_sideload( $file_array, 0 );
	@unlink( $tmp );

	if ( is_wp_error( $media_id ) ) return $media_id;

	return [
		'id'  => $media_id,
		'url' => wp_get_attachment_url( $media_id ),
	];
}

function ototoscreen_build_content( $movie, $description, $illustration_url ) {
	$title      = esc_html( $movie['title'] ?? 'タイトルなし' );
	$release    = esc_html( $movie['release_date'] ?? '不明' );
	$score      = esc_html( $movie['vote_average'] ?? '' );
	$poster_url = ! empty( $movie['poster_path'] )
		? esc_url( 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] )
		: '';

	return "
<div style=\"display:flex; gap:24px; align-items:flex-start;\">
  <figure style=\"flex:1;\">
    <img src=\"{$poster_url}\" alt=\"{$title} ポスター\" style=\"width:100%;\">
    <figcaption>公式ポスター</figcaption>
  </figure>
  <figure style=\"flex:1;\">
    <img src=\"" . esc_url( $illustration_url ) . "\" alt=\"{$title} イラスト\" style=\"width:100%;\">
    <figcaption>OtotoScreen オリジナルイラスト</figcaption>
  </figure>
</div>

<h2>作品情報</h2>
<ul>
  <li><strong>公開日：</strong>{$release}</li>
  <li><strong>評価：</strong>{$score} / 10</li>
</ul>

<h2>紹介</h2>
<p>" . esc_html( $description ) . "</p>

<p><small>※ 映画情報は <a href=\"https://www.themoviedb.org/\">TMDb</a> より取得しています。</small></p>
";
}

function ototoscreen_create_draft( $title, $content, $media_id = null ) {
	$post_id = wp_insert_post( [
		'post_title'   => sanitize_text_field( $title ),
		'post_content' => wp_kses_post( $content ),
		'post_status'  => 'draft',
		'post_type'    => 'post',
	] );

	if ( is_wp_error( $post_id ) ) return $post_id;

	if ( $media_id ) set_post_thumbnail( $post_id, $media_id );

	return $post_id;
}
