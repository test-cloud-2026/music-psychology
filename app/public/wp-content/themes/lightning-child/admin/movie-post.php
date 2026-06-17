<?php
/**
 * OtotoScreen — 管理画面「映画記事を作成」ページ
 */
if ( ! defined( 'ABSPATH' ) || ! current_user_can( 'manage_options' ) ) exit;
?>
<div class="wrap">
<h1>🎬 OtotoScreen — 映画記事を作成</h1>

<!-- 映画検索フォーム -->
<div class="card" style="max-width:860px; padding:20px; margin-top:20px;">
	<h2 style="margin-top:0; font-size:16px;">映画を検索する</h2>
	<div style="display:flex; gap:10px; align-items:center;">
		<input type="text" id="os-search-input"
			placeholder="タイトルを入力（例：セッション、ボヘミアン・ラプソディ）"
			style="flex:1; padding:8px 12px; font-size:15px;"
			onkeydown="if(event.key==='Enter') osSearch()">
		<button class="button button-primary" id="os-search-btn" onclick="osSearch()">
			検索
		</button>
	</div>
</div>

<!-- 検索結果 -->
<div id="os-results" style="max-width:860px; margin-top:16px;"></div>

<!-- 選択中の映画 + 生成ボタン -->
<div id="os-generate-area" style="display:none; max-width:860px; margin-top:16px;">
	<div class="card" style="padding:20px; display:flex; align-items:center; gap:20px;">
		<div style="flex:1;">
			<p style="margin:0; font-size:13px; color:#888;">選択中の映画</p>
			<p id="os-selected-title" style="margin:4px 0 0; font-size:16px; font-weight:bold;"></p>
		</div>
		<button class="button button-primary" id="os-generate-btn"
			style="height:40px; padding:0 24px; font-size:14px; white-space:nowrap;"
			onclick="osGenerate()">
			✨ 記事を生成する
		</button>
	</div>
</div>

<!-- 進捗・結果 -->
<div id="os-status" style="display:none; max-width:860px; margin-top:16px;">
	<div class="card" style="padding:20px;">
		<p id="os-status-msg" style="margin:0; font-size:15px; line-height:1.8;"></p>
	</div>
</div>

<style>
.os-card {
	display:flex; gap:14px; padding:14px; margin-bottom:8px;
	border:2px solid #ddd; border-radius:6px; cursor:pointer;
	background:#fff; transition:border-color .15s, background .15s;
}
.os-card:hover         { border-color:#2271b1; }
.os-card.os-active     { border-color:#2271b1; background:#f0f6ff; }
.os-card img           { width:54px; height:80px; object-fit:cover; border-radius:3px; flex-shrink:0; background:#eee; }
.os-card .os-title     { font-weight:600; font-size:15px; margin:0 0 4px; }
.os-card .os-year      { font-size:12px; color:#888; margin:0 0 6px; }
.os-card .os-overview  { font-size:13px; color:#555; margin:0;
	display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
</style>

<script>
var osMovie     = null;
var osMovieList = []; // 検索結果をここに保持（クリック時はインデックスで参照）
var osNonce     = '<?php echo esc_js( wp_create_nonce( 'ototoscreen_nonce' ) ); ?>';

function osSearch() {
	var q = document.getElementById('os-search-input').value.trim();
	if ( ! q ) return;

	var btn = document.getElementById('os-search-btn');
	btn.textContent = '検索中…';
	btn.disabled = true;
	document.getElementById('os-results').innerHTML = '';
	document.getElementById('os-generate-area').style.display = 'none';
	document.getElementById('os-status').style.display = 'none';
	osMovie     = null;
	osMovieList = [];

	jQuery.post( ajaxurl, { action: 'ototoscreen_search', query: q, nonce: osNonce },
		function( res ) {
			btn.textContent = '検索';
			btn.disabled = false;
			if ( ! res.success ) {
				document.getElementById('os-results').innerHTML =
					'<p style="color:red;">' + res.data.message + '</p>';
				return;
			}
			osRender( res.data );
		}
	).fail(function() {
		btn.textContent = '検索';
		btn.disabled = false;
		document.getElementById('os-results').innerHTML = '<p style="color:red;">通信エラーが発生しました。</p>';
	});
}

function osRender( movies ) {
	osMovieList = movies; // 検索結果を保持
	if ( ! movies.length ) {
		document.getElementById('os-results').innerHTML = '<p>映画が見つかりませんでした。別のキーワードで試してください。</p>';
		return;
	}
	var html = '<p style="color:#555; font-size:13px; margin-bottom:10px;">クリックして映画を選択してください</p>';
	movies.forEach( function( m, i ) {
		var poster = m.poster_path ? 'https://image.tmdb.org/t/p/w92' + m.poster_path : '';
		var year   = m.release_date ? m.release_date.slice( 0, 4 ) : '年不明';
		// onclick にはインデックス番号だけ渡す（JSON文字列のクォート問題を回避）
		html += '<div class="os-card" onclick="osSelect(this,' + i + ')">' +
			( poster ? '<img src="' + poster + '" alt="">' : '<img src="" style="visibility:hidden">' ) +
			'<div>' +
			'<p class="os-title">' + m.title + '</p>' +
			'<p class="os-year">' + year + '</p>' +
			'<p class="os-overview">' + ( m.overview || '（あらすじなし）' ) + '</p>' +
			'</div></div>';
	} );
	document.getElementById('os-results').innerHTML = html;
}

function osSelect( el, index ) {
	document.querySelectorAll('.os-card').forEach( function(c) { c.classList.remove('os-active'); } );
	el.classList.add('os-active');
	osMovie = osMovieList[ index ]; // インデックスから映画データを取得
	var year = osMovie.release_date ? '（' + osMovie.release_date.slice(0,4) + '）' : '';
	document.getElementById('os-selected-title').textContent = osMovie.title + year;
	document.getElementById('os-generate-area').style.display = 'block';
	document.getElementById('os-status').style.display = 'none';
}

function osGenerate() {
	if ( ! osMovie ) return;

	var btn = document.getElementById('os-generate-btn');
	btn.disabled = true;
	btn.textContent = '生成中…';

	var statusArea = document.getElementById('os-status');
	var msgEl      = document.getElementById('os-status-msg');
	statusArea.style.display = 'block';

	// 処理の進捗を疑似的に表示（実際の進捗はサーバー側で完了後に届く）
	var steps = [
		'⏳ ポスターから色を抽出中…',
		'⏳ Claude が紹介文を生成中… （30秒ほどかかります）',
		'⏳ Claude がイラストシーンを考案中…',
		'⏳ recraft-v3 がイラストを生成中… （60〜90秒ほどかかります）',
		'⏳ Claude が音楽と心理の解説を生成中…',
		'⏳ YouTube予告編を取得中…',
		'⏳ WordPress に記事を投稿中…',
	];
	var si = 0;
	msgEl.textContent = steps[0];
	var timer = setInterval( function() {
		si = Math.min( si + 1, steps.length - 1 );
		msgEl.textContent = steps[si];
	}, 18000 );

	jQuery.ajax({
		url:     ajaxurl,
		method:  'POST',
		timeout: 350000, // PHP の set_time_limit(300) より長く設定（5分50秒）
		data:    { action: 'ototoscreen_generate', movie: JSON.stringify(osMovie), nonce: osNonce },
		success: function( res ) {
			clearInterval( timer );
			btn.disabled = false;
			btn.textContent = '✨ 記事を生成する';
			if ( ! res.success ) {
				msgEl.innerHTML = '❌ ' + res.data.message;
				return;
			}
			msgEl.innerHTML =
				'✅ 記事を作成しました！<br>' +
				'<a href="' + res.data.edit_url    + '" target="_blank" style="margin-right:16px;">📝 記事を編集する</a>' +
				'<a href="' + res.data.preview_url + '" target="_blank">👁 プレビュー</a>';
		},
		error: function() {
			clearInterval( timer );
			btn.disabled = false;
			btn.textContent = '✨ 記事を生成する';
			// Lolipopのnginxプロキシが先にタイムアウトするが、PHPは完走して記事を作成している
			msgEl.innerHTML = '⏳ 生成に時間がかかっています（画像生成で3〜5分かかる場合があります）。<br>'
				+ '数分後に <a href="edit.php" target="_blank" style="font-weight:bold;">投稿一覧</a> を確認してください。'
				+ '<br><small style="color:#888; font-size:12px;">下書きとして保存されているはずです。</small>';
		},
	});
}
</script>
</div>
