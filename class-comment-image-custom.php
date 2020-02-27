<?php

/**
 * コメントに追加する画像のメディアライブラリへアップに必要なライブラリ
 */
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

/**
 * Comment Image Custom
 *
 * @package Comment_Image_Custom
 */
class Comment_Image_Custom {

	// このクラスのインスタンス
	protected static $instance = null;
	
	// アップロード最大バイトサイズ
	private $limit_file_size;
	
	// 画像添付枚数
	private $number_of_image_limit = 8;
	
	// コメントID保持
	private $comment_id;
	
	// ポストID保持
	private $post_id;
	
	// エラーメッセージ保持
	private $message = array();
	
	/**
	 * インスタンス生成
	 */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		} // end if
		return self::$instance;
	} // end get_instance

	/**
	 * コンストラクタ
	 */
	private function __construct() {

		// ホスト環境に保存可能かどうか
		if( ! $this->can_save_files() ) {
			$error_message = "サーバへのアップロードが許可されていません。";
			$this->save_error( $error_message );
			return;
		}

		// 昔のプラグインが使われていた場合、全てのコメントに対して新規処理を実行
		if( false == get_option( 'update_comment_images' ) || null == get_option( 'update_comment_images' ) ) {
			$this->update_old_comments();
		} // end if

		// サイトオプションを指定
		add_option( 'comment_image_toggle_state', 'enabled' );
		
		// スタイルシートとjsを読み込み
		add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_admin_scripts' ) );
		
		// 識別子フィールドを追加
		add_filter( 'comment_form' , array( $this, 'output_token' ) );
		add_filter( 'add_meta_boxes_comment' , array( $this, 'output_token' ) );

		// コメントフォームにアップロードフォームを追加
		add_action( 'comment_form_after' , array( $this, 'add_image_upload_form' ), 12);
		add_action( 'add_meta_boxes_comment' , array( $this, 'add_image_upload_form_admin' ), 13);
		
		// コメントが保存されるときにコメントイメージを保存
		add_filter( 'comment_post', array( $this, 'save_comment_image' ), 10, 1 );
		add_filter( 'edit_comment', array( $this, 'save_comment_image' ), 10, 1 );
		
		// コメントが削除される直前に、添付画像の関連コメントのカスタムフィールド、添付画像のparent_postを削除する
		add_action( 'delete_comment', array( $this, 'delete_comment_relate_image_customfield' ), 10, 2 );
		
		// 添付画像が削除される直前に、コメントに添付されている関連画像のカスタムフィールドから関連画像IDを削除する
		add_action( 'delete_attachment', array( $this, 'delete_attachment_relate_comment_customfield' ), 10, 1 );
		
		// コメントの内容の後ろに添付されている画像を追加する
		add_filter( 'comments_array', array( $this, 'display_comment_image' ) );

		// 管理画面コメント一覧に画像サムネを追加
		add_filter( 'manage_edit-comments_columns', array( $this, 'comment_has_image' ) );
		add_filter( 'manage_comments_custom_column', array( $this, 'comment_image_column' ), 20, 2 );

		// 管理画面画像一覧 絞り込み検索
		add_action( 'restrict_manage_posts', array( $this, 'comment_image_attachment_sort' ), 10, 1 );
		add_action( 'pre_get_posts', array( $this, 'comment_image_attachment_admin_query' ), 10, 1 );

		// メタボックスの追加
		add_action( 'add_meta_boxes', array( $this, 'add_comment_image_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_comment_image_display' ) );

		// 画像仮アップ用ajaxの追加
		add_action( 'wp_ajax_' . 'ajax_upload_recive_action', array( $this, 'ajax_upload_recive_js' ) );
		add_action( 'wp_ajax_nopriv_' . 'ajax_upload_recive_action', array( $this, 'ajax_upload_recive_js' ) );

		add_action( 'wp_footer', array( $this, 'comment_image_action_javascript') );

		// 管理画面で設定できるように将来変更する
		$this->limit_file_size = 5000000;  // 5MB
	} // end constructor

	function comment_image_action_javascript() {
	?>
		<script type='text/javascript'>
			jQuery(document).ready(function($) {
				if( ! window.FormData ) {
					alert("画像のアップロードに対応していないブラウザをお使いです。");
					return;
				}
				let ajaxurl = "<?php echo admin_url( 'admin-ajax.php' ); ?>";
				
				jQuery(document).on("change","input[name=\'image[]\']",function(){
					let fd = new FormData();
					let $this = jQuery(this);
					if ($this.val()!== "") {
						fd.append( 'file', $this.prop('files')[0] );
					}
					fd.append('comment-image-uniqid', jQuery("input[name=\'comment-image-uniqid\']").val() );
					fd.append('num', $this.attr('data-number') );
					fd.append('action', 'ajax_upload_recive_action');
					fd.append('nonce', '<?php echo wp_create_nonce( 'ajax_upload_recive_action' ); ?>');

					jQuery.ajax({
						url : ajaxurl,
						type: 'POST',
						dataType: 'json',
						cache: false,
						data : fd,
						processData : false,
						contentType: false,
						xhr : function(){
							XHR = jQuery.ajaxSettings.xhr();
							if(XHR.upload){
									XHR.upload.addEventListener("progress",function(e){
										progre = parseInt(e.loaded/e.total*10000)/100;
										//console.log(progre+"%") ;
										$this.parent().parent().children(".progress_bar").width(parseInt(progre/100*300*100)/100+"px").height("14px").html(progre+"%");
								}, false);
							}
							return XHR;
						},
						beforeSend: function () {
						},
						success: function (r) {
							console.table(r.data[0]);
						},
						error: function(XMLHttpRequest, textStatus, errorThrown){
							//console.log(textStatus);
							//console.log(errorThrown);
						}
					});
				});
			});
		</script>
		<?php
	}

	public function ajax_upload_recive_js() {
		$handle = 'ajax_upload_recive';
		$action = $handle . '_action';

		$nonce = filter_input( INPUT_POST, 'nonce' );

		// nonceチェック
		check_ajax_referer($action, 'nonce', true);

		try {
			$token = str_replace(
				array('.', '/'),
				'',
				filter_input( INPUT_POST, 'comment-image-uniqid', FILTER_SANITIZE_STRING )
			);
			$num = filter_input( INPUT_POST, 'num', FILTER_SANITIZE_STRING );

			$upload_dir = wp_upload_dir();
			$tmp_dir = "/tmp_files";
			$token_dir = $tmp_dir . "/" . $token;
		
			$tmp_dir_path = $upload_dir['basedir'] . $tmp_dir . '/';
			$token_dir_path = $tmp_dir_path . $token . '/';
			
			// ステートメント
			if( empty( $_FILES["file"]["tmp_name"] ) ){
				throw new Exception( 'ファイルがありません。' );
			}
			
			// 拡張子を取得
			$file_ext = substr( $_FILES["file"]["name"], strrpos( $_FILES["file"]["name"], '.' ) + 1 );

			// ファイルタイプを確認（改良する）
			if( ! $this->is_valid_file_type( $file_ext ) ) {
				throw new Exception( '画像ファイルが指定されていません。' );
			}
			
			// テンポラリディレクトリに同一ナンバーのファイルがあれば削除
			if( file_exists ( $token_dir_path ) ){
				$exist_file_names = $this->showDirFiles( $token_dir_path );
				foreach( $exist_file_names as $exist_file ){
					$exist_file_name_stack = explode( '.', $exist_file );
					$exist_num = array_shift( $exist_file_name_stack );
					if( $num === $exist_num ){
						$unlink_flag = unlink( $token_dir_path . $exist_file );
						if( ! $unlink_flag ){
							throw new Exception( 'ファイルのアップロード中にエラーが発生しました。再度ファイルを選択してください。' );
						}
					}
				}
			}

			/**
			 * 一時ファイルのアップロードディレクトリを指定
			 *
			 * @param	$path	該当ファイルがあるディレクトリの絶対パス
			 * @return			array $path
			 */
			
			add_filter('upload_dir', function( $path ) use( $token_dir ){
				$path['subdir'] = $token_dir;
				$path['path'] .= $path['subdir'];
				$path['url'] .= $path['subdir'];
				return $path;
			});
			
			// ディレクトリにアップロード
			$comment_image_file = wp_upload_bits(
				$num . '.' . $_FILES["file"]["name"],
				null,
				file_get_contents( $_FILES["file"]["tmp_name"] ),
				"tmp_files"
			);

			if ( $comment_image_file['error'] !== false ) {
				throw new Exception( $comment_image_file['error'] );
			}
			
			header('Content-Type: application/json');
			wp_send_json_success(array( $comment_image_file ));
		}
		catch (PDOException $e){
			header('Content-Type: application/json');
			wp_send_json_error( array( 'msg' => 'ERROR: ' . $e->getMessage() . "\n" ) );
		}
	}


	/*--------------------------------------------*
	 * Core Functions
	 *---------------------------------------------*/

	 /**
	  * 管理画面コメント一覧にコメントイメージカラム ラベルを追加する
	  *
	  * @param	array	$columns	The columns displayed on the page.
	  * @param	array	$columns	The updated array of columns.
	  */
	 function comment_has_image( $columns ) {
		 $columns['comment-image'] = '添付写真';
		 return $columns;
	 }

	 /**
	  * Renders the actual image for the comment.
	  * 管理画面コメント一覧にコメントイメージカラムを追加し、添付されている画像の一枚を表示する
	  *
	  * @param	string	The name of the column being rendered.
	  * @param	int		The ID of the comment being rendered.
	  * @since	1.8
	  */
	 function comment_image_column( $column_name, $comment_id ) {
		 if( 'comment-image' == strtolower( $column_name ) ) {
			 if( true == ( $comment_attachment_ids = get_comment_meta( $comment_id, 'comment_attachment_id', true ) ) ) {
				 $comment_image_data = wp_get_attachment_image_src( $comment_attachment_ids[0], 'thumbnail' );
				 $html = '<img src="' . $comment_image_data[0] . '" width="' . $comment_image_data['1'] . '" height="' . $comment_image_data['2'] . '" />';
				 echo $html;
	 		 } // end if
 		 }
	 }

	/**
	 * 管理画面 メディアライブラリの絞り込み検索
	 *
	 * @param	string	Current post_type
	 */
	public function comment_image_attachment_sort( $post_type ){
		if( $post_type !== 'attachment' ){
			return;
		}
		$current_status = get_query_var('post_status');
		$status_lists = array(
			'inherit' => '公開中の全ての画像',
			'private' => '非公開（削除可能）',
			'comment' => '公開中のコメント画像',
			'bug'			=> 'バグ（削除推薦）'
		)
		?>
		<select name="post_status">
			<option value="">すべてのメディア</option>
			<?php foreach( $status_lists as $status => $label ): ?>
			<option value="<?php echo $status; ?>"<?php echo ( $status === $current_status ) ? ' selected' : '' ; ?>><?php echo $label; ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * 絞り込み検索用クエリ
	 */
	public function comment_image_attachment_admin_query( $query ){
		if (
			! is_admin() ||
			! $query->is_main_query() ||
			! get_current_screen()->post_type === 'attachment'
		){
			return;
		}
		$status = filter_input( INPUT_GET, 'post_status' );
		if( $status === 'bug' ){
			$meta_query = array();
			$meta_query[] = array('key'=>'_wp_attachment_relation_comment_id',	'compare'=>'NOT EXISTS');
			$query->set('meta_query', $meta_query);
			$query->set('s', 'pid');
			$query->set('post_status', 'inherit');
		}else if( $status === 'comment' ){
			$meta_query = array();
			$meta_query[] = array('key'=>'_wp_attachment_relation_comment_id',	'compare'=>'EXISTS');
			$query->set('meta_query', $meta_query);
			$query->set('s', 'pid');
			$query->set('post_status', 'inherit');
		}else if( $status === 'private' || $status === 'inherit' ){
			$query->set('post_status', $status);
		}
	}

	 /**
	  * 旧プラグイン用の処理。全コメントの本文に画像のタグを追加
	  *
	  * Note that this option is not removed on deactivation because it will run *again* if the
	  * user ever re-activates it this duplicating the image.
	  */
	 private function update_old_comments() {

		// コメントイメージのアップデートフラグを一時停止
		update_option( 'update_comment_images', false );
		// 全コメントを回す
 		foreach( get_comments() as $comment ) {
			// コメントに画像が存在するとき
			if( true == ( $comment_attachment_ids = get_comment_meta( $comment->comment_ID, 'comment_attachment_id', true ) ) ) {
				// 先頭に表示されているコメント画像のサムネURLを取得
				$comment_image_data = wp_get_attachment_image_src( $comment_attachment_ids[0], 'thumbnail' );
				// コメント本文に画像を追加
				$comment->comment_content .= '<p class="comment-image">';
				$comment->comment_content .= '<img src="' . $comment_image_data[0] . '" alt="" />';
				$comment->comment_content .= '</p><!-- /.comment-image -->';
				// コメントデータをアプデ
				wp_update_comment( (array)$comment );
			} // end if
		} // end foreach
		// コメントイメージのアップデートフラグを再開
		update_option( 'update_comment_images', true );
	 } // end update_old_comments

	/**
	 *コメントが削除される直前に、添付画像の関連コメントのカスタムフィールドを削除する
	 *
	 * @param	$comment_id	Runs just before a comment is deleted from the database.
	 */
 	function delete_comment_relate_image_customfield( $comment_id ) {
		// コメントに関連する画像IDを配列で取得
		$attachment_ids_list = get_comment_meta( $comment_id, 'comment_attachment_id' );
		if( empty( $attachment_ids_list ) ){
			return;
		}
		foreach( $attachment_ids_list as $attachment_ids ){
			foreach( $attachment_ids as $attachment_id ){
				if( is_numeric( $attachment_id ) === false ){
					continue;
				}
				if( delete_post_meta( $attachment_id, '_wp_attachment_relation_comment_id' ) === false ){
					continue;
				}
				wp_update_post( array(
					'ID'           => $attachment_id,
					'post_status'   => 'private',
				) );
			}
		}
	}


	/**
	 *添付画像が削除される直前に、コメントに添付されている関連画像のカスタムフィールドから関連画像IDを削除する
	 *
	 * @param	$attachment_id Runs just before an attached file is deleted from the database.
	 */
 	function delete_attachment_relate_comment_customfield( $attachment_id ) {
		$comment_id_list = get_post_meta( $attachment_id, '_wp_attachment_relation_comment_id' );
		if( empty( $comment_id_list ) ) {
			return;
		}
		foreach ( $comment_id_list as $comment_id ){
			// アンシリアライズによる配列で取得
			$attachment_ids = get_comment_meta( (int)$comment_id, 'comment_attachment_id', true );
			$index = array_search( $attachment_id, $attachment_ids );
			array_splice( $attachment_ids, $index, 1 );
			if( count( $attachment_ids ) > 0 ){
				update_comment_meta( $comment_id, 'comment_attachment_id', $attachment_ids );
			}else{
				delete_comment_meta( $comment_id, 'comment_attachment_id' );
			}
		}
	}

	/**
	 * 識別子フィールドを追加
	 *
	 */
	function output_token(){
		echo '<input type="hidden" name="comment-image-uniqid" value="' . sha1(uniqid(mt_rand(), true)) . '">';
	}

	/**
	 * 管理画面へのアップロードフォームの追加
	 *
	 * @param	$comment
	 */
 	function add_image_upload_form_admin( $comment = NULL ) {
			// メタボックス追加
			add_meta_box('page_layout02', '写真', array($this, 'add_meta_boxes_comment_meta_box_image'), 'comment', 'normal', 'high', $comment);
	}
	/* 投稿画面に表示するフォームのHTMLソース */
	function add_meta_boxes_comment_meta_box_image($comment) {
		$this->add_image_upload_form($comment->comment_ID);
	}



	/**
	 * 画像アップロードフォームをコメントフォームに追加する
	 *
	 * @param	$comment_id	The ID of the post on which the comment is being added.
	 */
	 
 	function add_image_upload_form( $comment_id = 0 ) {
		// コメントIDが指定されているかどうか
		if (
					$comment_id
				&&	preg_match("/\d+/", $comment_id)
				&&	true == ( $comment = get_comment($comment_id) )
		){
			// コメントに関連づいた画像のattachment ID取得 (array)
			$comment_attachment_ids = get_comment_meta( $comment->comment_ID, 'comment_attachment_id', true );
		}
		
		if(
				 empty( $post_id )
			&& ! empty( $comment )
		){
			$post_id = $comment->comment_post_ID;
		}
		
		// 画像のアップロードが許可されているかどうか
		if (
				'disabled' == get_option( 'comment_image_toggle_state' )
			||	( isset( $post_id ) && 'disable' == get_post_meta( $post_id, 'comment_images_toggle', true ) 	)
		) {
			return '';
		}

		$output = '
			<table id="comment-image-wrapper">
			<caption style="color:#c00;">パーティー写真を掲載できます。<br /> (GIF, PNG, JPG, JPEG)<span id="comment-image-error"></span></caption>
		';
		for($i = 1; $i <= $this->number_of_image_limit ; $i++){
			$output .= "<tr>";
			// 添付のコメント画像があるかどうか
			if(
					! empty( $comment_attachment_ids )
				 &&	true == ( $comment_attachment_id = $comment_attachment_ids[$i-1] )
				 &&	true == ( $comment_images = wp_get_attachment_image_src( $comment_attachment_id, 'thumbnail' ) )
			){
				// 画像がある場合
				$output .= '<td class="comment-attachment"><b>画像' . $i . '枚目</b><br /><img src="' . $comment_images[0] . '" alt="画像を表示する" width="' . $comment_images[1] . '" height="' . $comment_images[2] . '" /><br />';
				
				if ( current_user_can( 'delete_post', $comment_attachment_id ) ){
					if ( EMPTY_TRASH_DAYS && MEDIA_TRASH ) {
						$output .= "<a class='submitdelete' href='" . get_admin_url() . wp_nonce_url( "post.php?action=trash&amp;post=$comment_attachment_id", 'trash-post_' . $comment_attachment_id ) . "'>" . __( 'Trash' ) . "</a>";
					} else {
						$delete_ays = !MEDIA_TRASH ? " onclick='return showNotice.warn();'" : '';
						$output .= "<a class='submitdelete'$delete_ays href='" . get_admin_url() . wp_nonce_url( "post.php?action=delete&amp;post=$comment_attachment_id", 'delete-post_' . $comment_attachment_id ) . "'>" . __( 'Delete Permanently' ) . "</a>";
					}
				}
				$output .= '</td>';
			}else{
				// 画像がない場合
				$output .= '
					<td>
						<div><input type="file" data-number="' . sprintf( "%02d", $i ) . '" name="image[]" class="comment_image"></div>
						<div class="progress_bar" style="width: 0%;"></div>
					</td>
				';
			}// end if
			$output .= "</tr>";
		}
		$output .= '</table><!-- #comment-image-wrapper -->';
		echo $output;
	} // end add_image_upload_form


	/**
	 * コメントフォームから送られてきた画像をシステムに追加する
	 * $comment_id:int
	 * wp-include/comment.php line1726
	 */
	function save_comment_image( $comment_id ) {

		// 投稿ページのIDがセットされているか
		if( false == ($post_id = $_POST['comment_post_ID']) ){
			$post_id =0;
		}
		
		// 識別子がセットされているか
		if( false == ($token = $_POST['comment-image-uniqid'])){
			$error_message = "識別子が指定されていません。";
			$this->save_error($error_message);
		}

		// テンポラリディレクトリの確認とファイルのアップロード
		$sub_dir = "/tmp_files/$token";
		$upload_dir = wp_upload_dir();
		$tmp_dir_path = $upload_dir['basedir'] . $sub_dir . '/';
		if( ! file_exists ( $tmp_dir_path ) ){
			return;
		}

		// テンポラリディレクトリ内のファイル一覧を取得
		$exist_file_names = $this->showDirFiles( $tmp_dir_path );

		$file = array();
		$attachment_ids = array();
		$today = date("Ymd");
		
		$this->comment_id = $comment_id;
		$this->post_id = $post_id;
		
		// アップロード先ディレクトリの設定
		add_filter('upload_dir', array( $this, 'add_slug_upload_dir' ) );

		foreach($exist_file_names as $exist_file):
			// 拡張子を取得
			$exist_file_name_stack = explode( '.', $exist_file );
			$exist_num = array_shift($exist_file_name_stack);
			$exist_file_ext = $exist_file_name_stack[ count( $exist_file_name_stack ) - 1 ];
			
			// ファイル名を変更
			$exist_file_name = 'pid' . $post_id . '_cid' . $comment_id . '_' . $today . '_' . $exist_num . '.' . $exist_file_ext ;

			// $_FILESのエイリアス配列を作成
			$file = array(
				'name' => $exist_file_name,
				'type' => 'image/' . $exist_file_ext,
				'tmp_name' => $tmp_dir_path . $exist_file,
				'error' => 0,
				'size' => filesize($tmp_dir_path . $exist_file)
			);
			$attachment_id = media_handle_sideload( $file, $post_id );

			@unlink( $file["tmp_name"] );

			// 多分ここがバグってる
			if ( is_wp_error($attachment_id)  ) {
				$error_message = "メディアに追加する際にエラーが発生しました。";
				$this->save_error($error_message);
			}
			
			// ポストのmetaにどのコメントとリンクしているかを保存する
			update_post_meta($attachment_id, '_wp_attachment_relation_comment_id', $comment_id);
			
			// コメントのmetaに画像を配列として保存。
			$attachment_ids[] = $attachment_id;

		endforeach;

		// 既に画像ファイルがカスタムフィールドにあるかどうかを確認
		$temp_attachment_ids = get_comment_meta( $comment_id, 'comment_attachment_id', true );
		
		// 配列に変換
		if( $temp_attachment_ids && !is_array($temp_attachment_ids) ){
			$temp_attachment_ids = array($temp_attachment_ids);
		}elseif( $temp_attachment_ids == "" ){
			$temp_attachment_ids = array();
		}
		
		// コメントのカスタムフィールドに添付画像のIDを配列で保存
		if( ( $attachment_ids || $temp_attachment_ids ) && ( is_array($attachment_ids) || is_array($temp_attachment_ids) ) ){
			$attachment_ids = array_merge( $temp_attachment_ids, $attachment_ids );
			update_comment_meta( $comment_id, 'comment_attachment_id', $attachment_ids );
		}
		
		// テンポラリディレクトリを削除
		@rmdir($tmp_dir_path);

	} // end save_comment_image


	/**
	 * コメントの内容の後ろに添付されている画像を追加する
	 *
	 * @param	$comment	The content of the comment.
	 */
	function display_comment_image( $comments ) {

		// コメントがあるかどうか確認
		if( count( $comments ) > 0 ) {

			// 全てのコメントをループする
			foreach( $comments as $comment ) {

				// コメントに画像があるかどうか確認
				if( true == ( $comment_attachment_ids = get_comment_meta( $comment->comment_ID, 'comment_attachment_id', true ) ) ) {
					// comment image metaから画像を取得
					$comment_image_data = wp_get_attachment_image_src( $comment_attachment_ids[0], 'thumbnail' );
					
					// コメントの内容の後に画像を表示
					$comment->comment_content .= '<p class="comment-image">';
						$comment->comment_content .= '<img src="' . $comment_image_data[0] . '" alt="画像を表示する" />';
					$comment->comment_content .= '</p><!-- /.comment-image -->';

				} // end if

			} // end foreach

		} // end if

		return $comments;

	} // end display_comment_image



	/**
	 * コメント画像のアップロードディレクトリを設定
	 *
	 * @param	$comment	The content of the comment.
	 */
	function add_slug_upload_dir ($path) {
		$comment_id = $this->comment_id;
		$post_id = $this->post_id;
		
		$path['subdir'] = '/comment/' . $post_id . '/c_' . $comment_id;
		$path['path'] .= $path['subdir'];
		$path['url'] .= $path['subdir'];
	
		return $path;
	}

	/*--------------------------------------------*
	 * 管理画面への追加用 アクション
	 *---------------------------------------------*/

	/**
	 * Adds the public JavaScript to the single post editor
	 */
	function add_admin_styles() {

		$screen = get_current_screen();
		if( 'post' === $screen->id || 'page' == $screen->id ) {
			wp_enqueue_style( 'comment-images-admin', plugins_url( '/comment-images-custom/css/admin.css' ) );
		} // end if

	} // end add_admin_styles

	/**
	 * Adds the public JavaScript to the single post editor
	 */
	function add_admin_scripts() {

		$screen = get_current_screen();
		if( 'post' === $screen->id || 'page' == $screen->id || 'comment' === $screen->id ) {

			wp_register_script( 'comment-images-admin', plugins_url( '/comment-images-custom/js/admin.min.js' ), array( 'jquery' ) );

			wp_localize_script(
				'comment-images-admin',
				'cm_imgs',
				array(
						'toggleConfirm' => '全ての投稿へのコメントへの画像投稿 承認を切り替えますか？'
				)
			);

			wp_enqueue_script( 'comment-images-admin' );

		} // end if

	} // end add_admin_scripts

	/*--------------------------------------------*
	 * エラー時のアクション
	 *---------------------------------------------*/

	/**
	* エラー時に管理画面へ表示するテキスト
	*/
	function save_error_notice() {
		
		$html  = '<div id="comment-image-notice" class="error">';
		$html .= '<p>';
		$html .= '<strong>Comment Images Notice : </strong>' . $this->message;
		$html .= '</p>';
		$html .= '</div>';
		echo $html;
	
	} // end save_error_notice

	/**
	* エラー時のコールバック
	*/
	private function save_error ( $message ) {
		if( empty($message) ) return;
		$option_name = 'comment_image_custom_error_info';
		$value_arr = get_option($option_name);
		if( !is_array($value_arr) && $value_arr ){
			$value_arr = array($value_arr);
		}elseif(empty($value_arr)){
			$value_arr = array();
		}
		$value_arr[] = $message;
		update_option($option_name, $value_arr);
		$this->message = $message;
		add_action( 'admin_notices', array( $this, 'save_error_notice' ) );
	} // end save_error

	
	/*--------------------------------------------*
	 * メタボックス関数
	 *---------------------------------------------*/

	 /**
	  * 管理画面の投稿ページにコメントイメージの設定メタボックスを表示する
	  * @version	1.0
	  * @since 		1.8
	  */
	 public function add_comment_image_meta_box() {
		 add_meta_box(
		 	'disable_comment_images',
		 	'コメントイメージ',
		 	array( $this, 'comment_images_display' ),
		 	'post',
		 	'side',
		 	'low'
		 );
	 } // end add_project_completion_meta_box

	 /**
	  * コメントへの画像の投稿を無効化する設定の表示
	  * @version	1.0
	  * @since 		1.8
	  */
	 public function comment_images_display( $post ) {

			wp_nonce_field( plugin_basename( __FILE__ ), 'comment_images_display_nonce' );

			$html = '<p class="comment-image-info">この投稿のコメントへの画像投稿<br /><small>（この投稿を保存後に適用されます）</small></p>';
			$html .= '<select name="comment_images_toggle" id="comment_images_toggle" class="comment_images_toggle_select">';
			$html .= '<option value="enable" ' . selected( 'enable', get_post_meta( $post->ID, 'comment_images_toggle', true ), false ) . '>許可する</option>';
			$html .= '<option value="disable" ' . selected( 'disable', get_post_meta( $post->ID, 'comment_images_toggle', true ), false ) . '>禁止する</option>';
			$html .= '</select>';

			$html .= '<hr />';
			
			$comment_image_state = 'disabled';
			if( '' == get_option( 'comment_image_toggle_state' ) || 'enabled' == get_option( 'comment_image_toggle_state' ) ) {
				$comment_image_state = 'enabled';
			} // end if
			
			$html .= '<p class="comment-image-warning">全ての投稿のコメントへの画像投稿</p>';
			if( 'enabled' == $comment_image_state ) {
			
				$html .= '<input type="button" class="button" name="comment_image_toggle" id="comment_image_toggle" value="禁止する"/>';
			
			} else {
			
				$html .= '<input type="button" class="button" name="comment_image_toggle" id="comment_image_toggle" value="許可する"/>';
			
			} // end if
			
			$html .= '<input type="hidden" name="comment_image_toggle_state" id="comment_image_toggle_state" value="' . $comment_image_state . '"/>';
			$html .= '<input type="hidden" name="comment_image_source" id="comment_image_source" value=""/>';

			echo $html;

	 } // end comment_images_display

	 /**
	  * 投稿ページでのコメントイメージ設定をmetaに保存する
	  */
	 public function save_comment_image_display( $post_id ) {

		 // パーミッションチェック
		 if( $this->user_can_save( $post_id, 'comment_images_display_nonce' ) ) {
			// Only do this if the source of the request is from the button
			if( isset( $_POST['comment_image_source'] ) && 'button' == $_POST['comment_image_source'] ) {
				if( '' == get_option( 'comment_image_toggle_state' ) || 'enabled' == get_option( 'comment_image_toggle_state' ) ) {
					$this->toggle_all_comment_images( 'disable' );
					update_option( 'comment_image_toggle_state', 'disabled' );
				} elseif ( 'disabled' == get_option( 'comment_image_toggle_state' ) ) {
					$this->toggle_all_comment_images( 'enable' );
					update_option( 'comment_image_toggle_state', 'enabled' );
				} // end if
			// we're doing this for the post-by-post basis with the select box
			} else {
			 	// Delete any existing meta data for the owner
				if( get_post_meta( $post_id, 'comment_images_toggle' ) ) {
					delete_post_meta( $post_id, 'comment_images_toggle' );
				} // end if
				update_post_meta( $post_id, 'comment_images_toggle', $_POST[ 'comment_images_toggle' ] );
			} // end if/else
		 } // end if

	 } // end save_comment_image_display

	/*--------------------------------------------*
	 * ユーティリティ
	 *--------------------------------------------*/
	/**
	* 全ての投稿、各々の投稿に関して、コメントイメージを有効化・無効化する関数
	* @param    string    $str_state    Whether or not we are enabling or disabling comment images.
	*/
	private function toggle_all_comment_images( $str_state ) {
		$args = array(
			'post_type'    =>    array( 'post', 'page' ),
			'post_status'  =>    array( 'publish', 'private' )
		);
		$query = new WP_Query( $args );
		while( $query->have_posts() ) {
			$query->the_post();
			// If post meta exists, delete it, then specify our value
			if( get_post_meta( get_the_ID(), 'comment_images_toggle' ) ) {
				delete_post_meta( get_the_ID(), 'comment_images_toggle' );
			}
			update_post_meta( get_the_ID(), 'comment_images_toggle', $str_state );
		}
		wp_reset_postdata();
	} // end toggle_all_comment_images

	/**
	 * アップロードされたファイルのバリデート用関数
	 *
	 * @param	$type	The file type attempting to be uploaded.
	 */
	private function is_valid_file_type( $type ) {

		$type = strtolower( trim ( $type ) );
		return 	$type == 'png' ||
				$type == 'gif' ||
				$type == 'jpg' ||
				$type == 'jpeg';

	} // end is_valid_file_type

	/**
	 * ホスティング環境で画像のアップロードが許可されているか判定する関数
	 */
	private function can_save_files() {
		return function_exists( 'file_get_contents' );
	} // end can_save_files

	/**
	* 現在のユーザが投稿のメタデータを保存できる権限を有しているか判定する関数
	*/
	private function user_can_save( $post_id, $nonce ) {
		$is_autosave = wp_is_post_autosave( $post_id );
		$is_revision = wp_is_post_revision( $post_id );
		$is_valid_nonce = ( isset( $_POST[ $nonce ] ) && wp_verify_nonce( $_POST[ $nonce ], plugin_basename( __FILE__ ) ) ) ? true : false;
		// return t or f
		return ! ( $is_autosave || $is_revision) && $is_valid_nonce;
	} // end user_can_save


	/**
	* ディレクトリ内のファイル名一覧の取得
	* @param		string		$dir	ディレクトリの絶対パス
	*/
	private function showDirFiles ( $dir ) {
		if ( $dirHandle = opendir ( $dir )) {
			$fileNames = array();
			while ( false !== ( $fileName = readdir ( $dirHandle ) ) ) {
				if ( $fileName != "." && $fileName != ".." ) {
						$fileNames[] = $fileName;
				}
			}
			closedir ( $dirHandle );
			return $fileNames;
		}
	}


} // end class