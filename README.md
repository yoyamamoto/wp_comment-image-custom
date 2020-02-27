# wp_comment-image-custom
Wordpressのコメントへ画像を追加できるプラグインの改良版。

## 使い方
- ダウンロードする
- pluginディレクトリへアップする
- 有効化する
- コメントフォーム内の`do_action( 'comment_form_after' );`に画像投稿フォームが表示される

## 色々
- コメントに投稿された画像は投稿に所属する
- 投稿(post_meta)、コメント(comment_meta)にそれぞれカスタムフィールドを持ち、どこの投稿IDのコメントIDに所属する画像（attachment）なのか相互に保存する

