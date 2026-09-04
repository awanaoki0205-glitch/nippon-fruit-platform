# Changelog

## 0.16.3

- Product Intelligenceを5列の見やすい比較表へ整理し、スマートフォン表示にも対応。
- 楽天・Yahoo!の保存済み寄附額とYahoo!公開バリエーションから比較価格を補完。
- 寄附額取得済み件数・未取得件数を明示。

## 0.16.2

- Product Intelligenceの入れ子配列を安全に展開し、PHPの参照渡しによる致命的エラーを修正。

## 0.16.1

- Product Intelligenceで配列形式の分類証拠・重量・数量・発送情報を安全に正規化し、Array to string conversion警告を修正。

## 0.16.0

- Administrator専用の機能フラグ個別契約（プラン標準／有効／無効）と各種上限設定を追加。
- Text AI・Image AIの契約状態と月間合算上限をサーバー側で検査。
- Product IntelligenceをClassification Intelligenceとは別画面で追加。
- 既存の分類証拠、重量、数量、品質、寄附額から比較データを正規化。比較目的のAI再実行は行わない。
- 自治体・カテゴリの複数選択、グループ内OR／グループ間AND、親子条件の正規化を追加。
- 複数条件をURLへ保存・復元し、条件チップから個別解除可能にした。
- スマートフォンの適用操作を画面下部に追従させた。
- 従来の単一条件URLと検索パラメータを維持。
