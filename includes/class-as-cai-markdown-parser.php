<?php
/**
 * Markdown to HTML Parser.
 *
 * Converts Markdown files (README, CHANGELOG, UPDATE) to styled HTML
 * for the plugin's documentation tab. Uses CSS classes instead of
 * inline styles for consistent theming.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.0
 * @updated 1.3.79
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Markdown_Parser {

	/**
	 * Parse Markdown to HTML.
	 *
	 * @param string $markdown Raw markdown content.
	 * @return string Rendered HTML.
	 */
	public function parse( $markdown ) {
		if ( empty( $markdown ) ) {
			return '';
		}

		// Escape ALL HTML first (security).
		$html = htmlspecialchars( $markdown, ENT_QUOTES, 'UTF-8' );

		$code_blocks  = array();
		$inline_codes = array();

		// 1. Extract and protect code blocks.
		$html = preg_replace_callback( '/```([a-z]*)\s*\n(.*?)\n\s*```/s', function ( $matches ) use ( &$code_blocks ) {
			$lang        = $matches[1] ? ' data-lang="' . $matches[1] . '"' : '';
			$label       = $matches[1] ? '<span class="as-cai-code-lang">' . $matches[1] . '</span>' : '';
			$code        = $matches[2];
			$placeholder = '___CODE_BLOCK_' . count( $code_blocks ) . '___';
			$code_blocks[ $placeholder ] = '<div class="as-cai-code-wrapper">' . $label . '<pre' . $lang . '><code>' . $code . '</code></pre></div>';
			return "\n" . $placeholder . "\n";
		}, $html );

		// 2. Extract and protect inline code.
		$html = preg_replace_callback( '/`([^`]+)`/', function ( $matches ) use ( &$inline_codes ) {
			$placeholder = '___INLINE_CODE_' . count( $inline_codes ) . '___';
			$inline_codes[ $placeholder ] = '<code>' . $matches[1] . '</code>';
			return $placeholder;
		}, $html );

		// 3. Process markdown syntax.

		// Horizontal rules.
		$html = preg_replace( '/^---+$/m', '<hr>', $html );

		// Headers.
		$html = preg_replace( '/^#### (.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $html );

		// Bold.
		$html = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );

		// Italic.
		$html = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $html );

		// Images (before links to prevent conflict).
		$html = preg_replace( '/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="as-cai-prose-img">', $html );

		// Links.
		$html = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html );

		// Blockquotes.
		$html = preg_replace_callback( '/((?:^&gt; .+$\n?)+)/m', function ( $matches ) {
			$text = preg_replace( '/^&gt; /m', '', trim( $matches[1] ) );
			return '<blockquote>' . $text . '</blockquote>';
		}, $html );

		// Tables.
		$html = preg_replace_callback( '/((?:^\|.+\|$\n?){2,})/m', function ( $matches ) {
			$rows      = array_filter( explode( "\n", trim( $matches[1] ) ) );
			$output    = '<div class="as-cai-table-scroll"><table>';
			$in_header = true;
			$in_body   = false;
			foreach ( $rows as $row ) {
				$row = trim( $row, '| ' );
				// Skip separator rows.
				if ( preg_match( '/^[\s\-\|:]+$/', $row ) ) {
					$in_header = false;
					if ( ! $in_body ) {
						$output .= '</thead><tbody>';
						$in_body = true;
					}
					continue;
				}
				$cells = array_map( 'trim', explode( '|', $row ) );
				$tag   = $in_header ? 'th' : 'td';
				if ( $in_header && ! $in_body ) {
					$output .= '<thead>';
				}
				$output .= '<tr>';
				foreach ( $cells as $cell ) {
					$output .= '<' . $tag . '>' . $cell . '</' . $tag . '>';
				}
				$output .= '</tr>';
			}
			if ( $in_body ) {
				$output .= '</tbody>';
			} elseif ( $in_header ) {
				$output .= '</thead>';
			}
			$output .= '</table></div>';
			return $output;
		}, $html );

		// Task lists (before regular lists).
		$html = preg_replace( '/^[\-\*] \[x\] (.+)$/m', '<li class="as-cai-task"><span class="as-cai-task-done">&#10003;</span> $1</li>', $html );
		$html = preg_replace( '/^[\-\*] \[ \] (.+)$/m', '<li class="as-cai-task"><span class="as-cai-task-open">&#9675;</span> $1</li>', $html );

		// Nested lists (2 spaces or tab indent).
		$html = preg_replace( '/^  [\-\*] (.+)$/m', '<li class="as-cai-nested">$1</li>', $html );

		// Regular lists.
		$html = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $html );

		// Numbered lists.
		$html = preg_replace( '/^\d+\. (.+)$/m', '<li>$1</li>', $html );

		// Group list items into <ul>.
		$html = preg_replace( '/((?:<li[^>]*>.*?<\/li>\s*)+)/s', '<ul>$1</ul>', $html );

		// Nest nested items properly.
		$html = preg_replace_callback( '/<ul>(.*?)<\/ul>/s', function ( $matches ) {
			$content = $matches[1];
			// Wrap consecutive nested items in a sub-ul.
			$content = preg_replace( '/((?:<li class="as-cai-nested">.*?<\/li>\s*)+)/s', '<ul class="as-cai-sublist">$1</ul>', $content );
			return '<ul>' . $content . '</ul>';
		}, $html );

		// Paragraphs.
		$html = '<p>' . preg_replace( '/\n\n+/', '</p><p>', $html ) . '</p>';

		// Clean up empty/invalid paragraphs around block elements.
		$block_tags = 'h[1-4]|hr|pre|table|ul|ol|div|blockquote';
		$html = preg_replace( '/<p>\s*(<(?:' . $block_tags . ')[^>]*>)/s', '$1', $html );
		$html = preg_replace( '/(<\/(?:' . $block_tags . ')>)\s*<\/p>/s', '$1', $html );
		$html = preg_replace( '/<p>\s*<\/p>/', '', $html );

		// Line breaks within paragraphs.
		$html = nl2br( $html );

		// 4. Restore protected content.
		foreach ( $code_blocks as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}
		foreach ( $inline_codes as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}

		return $html;
	}
}
