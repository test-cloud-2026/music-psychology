<?php
/**
 * Lightning Child theme functions
 *
 * @package lightning
 */

/************************************************
 * 独自CSSファイルの読み込み処理
 *
 * 主に CSS を SASS で 書きたい人用です。 素の CSS を直接書くなら style.css に記載してかまいません.
 */

// 独自のCSSファイル（assets/css/）を読み込む場合は true に変更してください.
$my_lightning_additional_css = true;

if ( $my_lightning_additional_css ) {
	// 公開画面側のCSSの読み込み.
	add_action(
		'wp_enqueue_scripts',
		function() {
			wp_enqueue_style(
				'my-lightning-custom',
				get_stylesheet_directory_uri() . '/assets/css/style.css',
				array( 'lightning-design-style' ),
				filemtime( dirname( __FILE__ ) . '/assets/css/style.css' )
			);
		}
	);
	// 編集画面側のCSSの読み込み.
	add_action(
		'enqueue_block_editor_assets',
		function() {
			wp_enqueue_style(
				'my-lightning-editor-custom',
				get_stylesheet_directory_uri() . '/assets/css/editor.css',
				array( 'wp-edit-blocks', 'lightning-gutenberg-editor' ),
				filemtime( dirname( __FILE__ ) . '/assets/css/editor.css' )
			);
		}
	);
}

/************************************************
 * Google Fonts（Noto Sans JP）の読み込み
 */
add_action(
	'wp_enqueue_scripts',
	function() {
		wp_enqueue_style(
			'ototoscreen-google-fonts',
			'https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700&display=swap',
			array(),
			null
		);
	}
);

/************************************************
 * OtotoScreen — 映画記事自動生成機能
 * 管理画面から映画を選んで記事を生成できる
 ************************************************/

require_once get_stylesheet_directory() . '/includes/ototoscreen-api.php';

// 管理画面メニューを追加
add_action( 'admin_menu', function() {
	add_menu_page(
		'OtotoScreen 映画投稿',
		'OtotoScreen',
		'manage_options',
		'ototoscreen-movie-post',
		function() {
			include get_stylesheet_directory() . '/admin/movie-post.php';
		},
		'dashicons-video-alt2',
		30
	);
} );

// AJAX: 映画検索（TMDb）
add_action( 'wp_ajax_ototoscreen_search', function() {
	check_ajax_referer( 'ototoscreen_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '権限がありません。' ] );

	$query = sanitize_text_field( $_POST['query'] ?? '' );
	if ( ! $query ) wp_send_json_error( [ 'message' => '検索ワードを入力してください。' ] );

	$movies = ototoscreen_tmdb_search( $query );
	wp_send_json_success( $movies );
} );

// AJAX: 記事を生成（Claude + gpt-image-1 + WordPress投稿）
add_action( 'wp_ajax_ototoscreen_generate', function() {
	@set_time_limit( 300 );
	check_ajax_referer( 'ototoscreen_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '権限がありません。' ] );

	// 二重実行防止：同時に複数のリクエストが来ても1つだけ処理する
	$lock_key = 'ototoscreen_generating';
	if ( get_transient( $lock_key ) ) {
		wp_send_json_error( [ 'message' => '現在別の記事を生成中です。完了するまでお待ちください（最大5分）。' ] );
	}
	set_transient( $lock_key, true, 360 ); // 6分間ロック（処理上限5分 + 余裕1分）

	$movie = json_decode( stripslashes( $_POST['movie'] ?? '' ), true );
	if ( ! $movie ) wp_send_json_error( [ 'message' => '映画データが不正です。' ] );

	$movie_id    = intval( $movie['id'] ?? 0 );
	$title       = $movie['title']        ?? 'タイトルなし';
	$overview    = $movie['overview']     ?? '';
	$release     = $movie['release_date'] ?? '';
	$poster_url  = ! empty( $movie['poster_path'] )
		? 'https://image.tmdb.org/t/p/w500' . $movie['poster_path']
		: '';

	// ① ポスターから色を抽出
	$colors = ototoscreen_extract_colors( $poster_url );

	// ② Claude: 日本語紹介文を生成
	$description = ototoscreen_generate_description( $title, $overview, $release );
	if ( is_wp_error( $description ) ) {
		delete_transient( $lock_key );
		wp_send_json_error( [ 'message' => 'Claude エラー（紹介文）: ' . $description->get_error_message() ] );
	}

	// ③ Claude: イラストシーンを英語で考案
	$scene = ototoscreen_generate_scene( $title, $description );
	if ( is_wp_error( $scene ) ) {
		delete_transient( $lock_key );
		wp_send_json_error( [ 'message' => 'Claude エラー（シーン）: ' . $scene->get_error_message() ] );
	}

	// ④ gpt-image-1: 一筆書きイラストを生成
	$image_bytes = ototoscreen_generate_illustration( trim( $scene ), $colors );
	if ( is_wp_error( $image_bytes ) ) {
		delete_transient( $lock_key );
		wp_send_json_error( [ 'message' => 'OpenAI エラー: ' . $image_bytes->get_error_message() ] );
	}

	// ⑤ WordPress メディアにイラストをアップロード
	$media = ototoscreen_upload_illustration( $image_bytes, $movie_id );
	if ( is_wp_error( $media ) ) {
		delete_transient( $lock_key );
		wp_send_json_error( [ 'message' => '画像アップロードエラー: ' . $media->get_error_message() ] );
	}

	// ⑥ Claude: GIM視点の解説を生成
	$gim_commentary = '';
	$gim_result = ototoscreen_generate_gim_commentary( $title, $description );
	if ( ! is_wp_error( $gim_result ) ) $gim_commentary = $gim_result;

	// ⑦ TMDb: YouTube予告編を取得
	$trailer_embed = ototoscreen_get_youtube_trailer( $movie_id );

	// ⑧ 記事（下書き）を作成
	$content = ototoscreen_build_content( $movie, $description, $media['url'], $gim_commentary, $trailer_embed );
	$post_id = ototoscreen_create_draft( $title, $content, $media['id'] );
	if ( is_wp_error( $post_id ) ) {
		delete_transient( $lock_key );
		wp_send_json_error( [ 'message' => '記事作成エラー: ' . $post_id->get_error_message() ] );
	}

	delete_transient( $lock_key ); // 正常完了時にロック解除
	wp_send_json_success( [
		'post_id'     => $post_id,
		'edit_url'    => admin_url( "post.php?post={$post_id}&action=edit" ),
		'preview_url' => get_preview_post_link( $post_id ),
	] );
} );

/************************************************
 * 独自の処理を必要に応じて書き足します
 */
