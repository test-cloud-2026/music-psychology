<?php
/**
 * OtotoScreen — 外部API連携関数
 * TMDb / Claude / Replicate FLUX.1 [schnell] / WordPress の各処理をまとめた関数群
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
	if ( ! $poster_url ) return 'warm terracotta and soft steel blue';

	$response = wp_remote_get( $poster_url, [ 'timeout' => 15 ] );
	if ( is_wp_error( $response ) ) return 'warm terracotta and soft steel blue';

	if ( ! function_exists( 'imagecreatefromstring' ) ) return 'warm terracotta and soft steel blue';

	$image = @imagecreatefromstring( wp_remote_retrieve_body( $response ) );
	if ( ! $image ) return 'warm terracotta and soft steel blue';

	$thumb = imagecreatetruecolor( 50, 75 );
	imagecopyresampled( $thumb, $image, 0, 0, 0, 0, 50, 75, imagesx( $image ), imagesy( $image ) );
	imagedestroy( $image );

	$vivid_buckets  = []; // 彩度のある色（dominant・accent候補）
	$neutral_buckets = []; // 中間トーンのグレー（vivid が少ない時の補完用）

	for ( $x = 0; $x < 50; $x++ ) {
		for ( $y = 0; $y < 75; $y++ ) {
			$rgb  = imagecolorat( $thumb, $x, $y );
			$r    = ( $rgb >> 16 ) & 0xFF;
			$g    = ( $rgb >> 8 )  & 0xFF;
			$b    =   $rgb         & 0xFF;

			$brightness = ( $r + $g + $b ) / 3;
			if ( $brightness < 40 || $brightness > 220 ) continue; // ほぼ黒・白はスキップ

			$max_ch = max( $r, $g, $b );
			$min_ch = min( $r, $g, $b );
			$sat    = $max_ch > 0 ? ( $max_ch - $min_ch ) / $max_ch : 0;

			$name = ototoscreen_describe_color( $r, $g, $b );

			if ( $sat > 0.18 && $name !== 'muted slate gray' ) {
				$vivid_buckets[ $name ] = ( $vivid_buckets[ $name ] ?? 0 ) + 1;
			} else {
				$neutral_buckets[ $name ] = ( $neutral_buckets[ $name ] ?? 0 ) + 1;
			}
		}
	}
	imagedestroy( $thumb );

	arsort( $vivid_buckets );
	arsort( $neutral_buckets );

	$vivid_keys   = array_keys( $vivid_buckets );
	$neutral_keys = array_keys( $neutral_buckets );

	// 支配色：最も頻度の高い鮮やかな色
	$dominant = $vivid_keys[0] ?? $neutral_keys[0] ?? 'warm terracotta';

	// アクセント色：支配色と異なる2番目の鮮やかな色
	$accent = null;
	foreach ( $vivid_keys as $k ) {
		if ( $k !== $dominant ) { $accent = $k; break; }
	}
	if ( ! $accent ) {
		foreach ( $neutral_keys as $k ) {
			if ( $k !== $dominant ) { $accent = $k; break; }
		}
	}
	$accent = $accent ?? 'soft steel blue';

	return $dominant . ' and ' . $accent;
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
Suggest ONE iconic element for a minimalist single-line art illustration that best captures this movie's essence.
Write ONLY the subject in English, 10 words or less.
This can be a person in action, a character gesture, a key scene, an instrument, or a symbolic landscape.
Focus on ONE central element — avoid complex compositions with multiple unrelated elements or crowds.
Good examples: \"a kabuki actor mid-dance with fan raised\", \"hands pressing piano keys\", \"a lone violin on stage\", \"a dancer leaping\"

Movie title: {$title}
Description: {$description}";

	return ototoscreen_claude_request( $prompt, 200 );
}

function ototoscreen_generate_gim_commentary( $title, $description ) {
	$prompt = "あなたは音楽心理学の専門家です。
以下の映画における音楽の使われ方について、心理学の視点から200〜250文字程度で解説してください。

【映画タイトル】{$title}
【映画の紹介】{$description}

条件：
- 映画の音楽が登場人物の心理描写をどのように強化・表現しているかに注目する
- 視聴者側に音楽がどのような心理的効果（感情移入・緊張・解放感など）をもたらしているかも触れる
- 音楽心理学の知見を活かしつつ、一般の映画ファンにも分かりやすい言葉で書く
- 解説文のみを出力する（見出し不要）";

	return ototoscreen_claude_request( $prompt, 600 );
}

// =============================================
// TMDb — YouTube 予告編を取得する
// =============================================

function ototoscreen_get_youtube_trailer( $movie_id ) {
	// 日本語 → 英語の順で探す
	foreach ( [ 'ja-JP', 'en-US' ] as $lang ) {
		$response = wp_remote_get(
			"https://api.themoviedb.org/3/movie/{$movie_id}/videos",
			[ 'timeout' => 10, 'body' => [ 'api_key' => OTOTOSCREEN_TMDB_API_KEY, 'language' => $lang ] ]
		);
		if ( is_wp_error( $response ) ) continue;

		$videos = json_decode( wp_remote_retrieve_body( $response ), true )['results'] ?? [];
		foreach ( $videos as $v ) {
			if ( ( $v['site'] ?? '' ) === 'YouTube' && ( $v['type'] ?? '' ) === 'Trailer' ) {
				$key = esc_attr( $v['key'] );
				return '<iframe width="100%" height="400" src="https://www.youtube.com/embed/' . $key . '" frameborder="0" allowfullscreen></iframe>';
			}
		}
	}
	return ''; // 予告編が見つからない場合
}

// =============================================
// Replicate API — FLUX.1 [schnell] でイラスト生成
// =============================================

function ototoscreen_generate_illustration( $scene, $colors ) {
	if ( ! defined( 'OTOTOSCREEN_REPLICATE_API_TOKEN' ) || ! OTOTOSCREEN_REPLICATE_API_TOKEN ) {
		return new WP_Error( 'replicate_error', 'OTOTOSCREEN_REPLICATE_API_TOKEN が設定されていません。' );
	}

	// 2色を個別のブロブとして明示するために分割する
	$color_parts = explode( ' and ', $colors, 2 );
	$color1      = trim( $color_parts[0] ?? 'warm terracotta' );
	$color2      = trim( $color_parts[1] ?? 'soft steel blue' );

	$style  = "minimalist one-line art, single continuous flowing black pen line on pure white background, "
	        . "no color fill inside the outline, no shading, no cross-hatching, no internal detail lines, "
	        . "only the essential outer contour drawn in one unbroken flowing stroke, "
	        . "the main subject is drawn large and centered in the middle of the canvas, "
	        . "one soft organic {$color1} blob shape and one soft organic {$color2} blob shape "
	        . "are each placed independently across the canvas, spread apart from each other, "
	        . "each blob is a flat muted solid shape with no outline sitting behind the black lines, "
	        . "clean minimal editorial style, wide horizontal composition, ";
	$prompt = $style . trim( $scene );

	$response = wp_remote_post( 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions', [
		'timeout' => 70,
		'headers' => [
			'Authorization' => 'Bearer ' . OTOTOSCREEN_REPLICATE_API_TOKEN,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'wait=60',
			'Cancel-After'  => '90s',
		],
		'body' => wp_json_encode( [
			'input' => [
				'prompt'               => $prompt,
				'aspect_ratio'         => '16:9', // 292×163.375 ≈ 16:9（横長）
				'num_outputs'          => 1,
				'output_format'        => 'png',
				'num_inference_steps'  => 4,    // schnell の最大値（品質優先）
				'go_fast'              => false, // fp8量子化を無効にして品質を上げる
			],
		] ),
	] );

	if ( is_wp_error( $response ) ) return $response;

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! in_array( $code, [ 200, 201 ], true ) ) {
		$message = $body['detail'] ?? $body['error'] ?? wp_remote_retrieve_body( $response );
		return new WP_Error( 'replicate_error', 'Replicate API エラー: ' . wp_strip_all_tags( (string) $message ) );
	}

	for ( $i = 0; $i < 6; $i++ ) {
		$status = $body['status'] ?? '';
		if ( 'succeeded' === $status ) {
			$output    = $body['output'] ?? null;
			$image_url = is_array( $output ) ? ( $output[0] ?? '' ) : $output;
			if ( ! $image_url ) {
				return new WP_Error( 'replicate_error', 'Replicate の画像URLを取得できませんでした。' );
			}

			$image_response = wp_remote_get( $image_url, [ 'timeout' => 30 ] );
			if ( is_wp_error( $image_response ) ) return $image_response;
			return wp_remote_retrieve_body( $image_response );
		}

		if ( in_array( $status, [ 'failed', 'canceled' ], true ) ) {
			return new WP_Error( 'replicate_error', 'Replicate 画像生成失敗: ' . ( $body['error'] ?? 'unknown error' ) );
		}

		$get_url = $body['urls']['get'] ?? '';
		if ( ! $get_url ) break;
		sleep( 5 );
		$poll_response = wp_remote_get( $get_url, [
			'timeout' => 15,
			'headers' => [ 'Authorization' => 'Bearer ' . OTOTOSCREEN_REPLICATE_API_TOKEN ],
		] );
		if ( is_wp_error( $poll_response ) ) return $poll_response;
		$body = json_decode( wp_remote_retrieve_body( $poll_response ), true );
	}

	return new WP_Error( 'replicate_error', 'Replicate 画像生成が時間内に完了しませんでした。' );
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

function ototoscreen_build_content( $movie, $description, $illustration_url, $gim_commentary = '', $trailer_embed = '' ) {
	$title      = esc_html( $movie['title'] ?? 'タイトルなし' );
	$release    = esc_html( $movie['release_date'] ?? '不明' );
	$score      = esc_html( $movie['vote_average'] ?? '' );
	$poster_url = ! empty( $movie['poster_path'] )
		? esc_url( 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'] )
		: '';

	$content = "
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
";

	if ( $gim_commentary ) {
		$content .= "
<h2>音楽と心理</h2>
<p>" . esc_html( $gim_commentary ) . "</p>
";
	}

	if ( $trailer_embed ) {
		$content .= "
<h2>予告編</h2>
" . $trailer_embed . "
";
	}

	$content .= "
<p><small>※ 映画情報は <a href=\"https://www.themoviedb.org/\">TMDb</a> より取得しています。</small></p>
";

	return $content;
}

function ototoscreen_create_draft( $title, $content, $media_id = null ) {
	// iframeを含む自動生成コンテンツを保存するため、管理者権限下でKSESフィルターを一時解除する。
	// この関数は manage_options チェック済みのAJAXハンドラからのみ呼ばれる。
	kses_remove_filters();

	$post_args = [
		'post_title'   => sanitize_text_field( $title ),
		'post_content' => $content,
		'post_status'  => 'draft',
		'post_type'    => 'post',
	];

	// アイキャッチをpost作成と同時にmeta_inputで設定（set_post_thumbnailより確実）
	if ( $media_id ) {
		$post_args['meta_input'] = [ '_thumbnail_id' => intval( $media_id ) ];
	}

	$post_id = wp_insert_post( $post_args );
	kses_add_filters();

	if ( is_wp_error( $post_id ) ) return $post_id;

	return $post_id;
}
