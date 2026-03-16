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
	 * Uses a line-by-line approach to avoid nl2br() issues with block elements.
	 *
	 * @param string $markdown Raw markdown content.
	 * @return string Rendered HTML.
	 */
	public function parse( $markdown ) {
		if ( empty( $markdown ) ) {
			return '';
		}

		// Escape ALL HTML first (security).
		$text = htmlspecialchars( $markdown, ENT_QUOTES, 'UTF-8' );

		$code_blocks  = array();
		$inline_codes = array();

		// 1. Extract and protect fenced code blocks.
		$text = preg_replace_callback( '/```([a-z]*)\s*\n(.*?)\n\s*```/s', function ( $matches ) use ( &$code_blocks ) {
			$lang        = $matches[1] ? ' data-lang="' . $matches[1] . '"' : '';
			$label       = $matches[1] ? '<span class="as-cai-code-lang">' . $matches[1] . '</span>' : '';
			$code        = $matches[2];
			$placeholder = '___CODE_BLOCK_' . count( $code_blocks ) . '___';
			$code_blocks[ $placeholder ] = '<div class="as-cai-code-wrapper">' . $label . '<pre' . $lang . '><code>' . $code . '</code></pre></div>';
			return "\n" . $placeholder . "\n";
		}, $text );

		// 2. Extract and protect inline code.
		$text = preg_replace_callback( '/`([^`]+)`/', function ( $matches ) use ( &$inline_codes ) {
			$placeholder = '___INLINE_CODE_' . count( $inline_codes ) . '___';
			$inline_codes[ $placeholder ] = '<code>' . $matches[1] . '</code>';
			return $placeholder;
		}, $text );

		// 3. Process line-by-line into blocks.
		$lines  = explode( "\n", $text );
		$output = array();
		$buffer = array(); // Collects lines for current paragraph.
		$in_list = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Empty line = end current block.
			if ( $trimmed === '' ) {
				$this->flush_buffer( $buffer, $output, $in_list );
				continue;
			}

			// Code block placeholder.
			if ( preg_match( '/^___CODE_BLOCK_\d+___$/', $trimmed ) ) {
				$this->flush_buffer( $buffer, $output, $in_list );
				$output[] = $trimmed;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^---+$/', $trimmed ) ) {
				$this->flush_buffer( $buffer, $output, $in_list );
				$output[] = '<hr>';
				continue;
			}

			// Headers.
			if ( preg_match( '/^(#{1,4}) (.+)$/', $trimmed, $m ) ) {
				$this->flush_buffer( $buffer, $output, $in_list );
				$level    = strlen( $m[1] );
				$output[] = '<h' . $level . '>' . $this->inline( $m[2] ) . '</h' . $level . '>';
				continue;
			}

			// Table row.
			if ( preg_match( '/^\|.+\|$/', $trimmed ) ) {
				if ( ! $in_list && ! empty( $buffer ) && ! preg_match( '/^\|/', trim( $buffer[0] ) ) ) {
					$this->flush_buffer( $buffer, $output, $in_list );
				}
				$buffer[] = $trimmed;
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^&gt; (.*)$/', $trimmed, $m ) ) {
				if ( ! empty( $buffer ) && ! preg_match( '/^&gt; /', trim( $buffer[0] ) ) ) {
					$this->flush_buffer( $buffer, $output, $in_list );
				}
				$buffer[] = $trimmed;
				continue;
			}

			// Task list.
			if ( preg_match( '/^[\-\*] \[(x| )\] (.+)$/', $trimmed, $m ) ) {
				if ( ! $in_list ) {
					$this->flush_buffer( $buffer, $output, $in_list );
					$in_list = true;
				}
				$done     = $m[1] === 'x';
				$icon     = $done ? '<span class="as-cai-task-done">&#10003;</span>' : '<span class="as-cai-task-open">&#9675;</span>';
				$buffer[] = '<li class="as-cai-task">' . $icon . ' ' . $this->inline( $m[2] ) . '</li>';
				continue;
			}

			// Nested list item (2+ spaces or tab).
			if ( preg_match( '/^(?:  +|\t)[\-\*] (.+)$/', $line, $m ) ) {
				if ( ! $in_list ) {
					$this->flush_buffer( $buffer, $output, $in_list );
					$in_list = true;
				}
				$buffer[] = '<li class="as-cai-nested">' . $this->inline( $m[1] ) . '</li>';
				continue;
			}

			// Unordered list item.
			if ( preg_match( '/^[\-\*] (.+)$/', $trimmed, $m ) ) {
				if ( ! $in_list ) {
					$this->flush_buffer( $buffer, $output, $in_list );
					$in_list = true;
				}
				$buffer[] = '<li>' . $this->inline( $m[1] ) . '</li>';
				continue;
			}

			// Ordered list item.
			if ( preg_match( '/^\d+\. (.+)$/', $trimmed, $m ) ) {
				if ( ! $in_list ) {
					$this->flush_buffer( $buffer, $output, $in_list );
					$in_list = true;
				}
				$buffer[] = '<li>' . $this->inline( $m[1] ) . '</li>';
				continue;
			}

			// Regular text line — if we were in a list, flush first.
			if ( $in_list ) {
				$this->flush_buffer( $buffer, $output, $in_list );
			}
			$buffer[] = $trimmed;
		}

		// Flush remaining buffer.
		$this->flush_buffer( $buffer, $output, $in_list );

		// Build final HTML.
		$html = implode( "\n", $output );

		// 4. Restore protected content.
		foreach ( $code_blocks as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}
		foreach ( $inline_codes as $placeholder => $code_html ) {
			$html = str_replace( $placeholder, $code_html, $html );
		}

		return $html;
	}

	/**
	 * Flush the current line buffer into the output array.
	 *
	 * @param array &$buffer   Current collected lines.
	 * @param array &$output   Output blocks.
	 * @param bool  &$in_list  Whether we are inside a list.
	 */
	private function flush_buffer( &$buffer, &$output, &$in_list ) {
		if ( empty( $buffer ) ) {
			$in_list = false;
			return;
		}

		$first = $buffer[0];

		// List items.
		if ( $in_list || preg_match( '/^<li[\s>]/', $first ) ) {
			$list_html = '<ul>';
			$nested    = array();
			$flush_nested = function () use ( &$nested, &$list_html ) {
				if ( ! empty( $nested ) ) {
					$list_html .= '<ul class="as-cai-sublist">' . implode( '', $nested ) . '</ul>';
					$nested = array();
				}
			};
			foreach ( $buffer as $li ) {
				if ( strpos( $li, 'as-cai-nested' ) !== false ) {
					$nested[] = $li;
				} else {
					$flush_nested();
					$list_html .= $li;
				}
			}
			$flush_nested();
			$list_html .= '</ul>';
			$output[]   = $list_html;
			$buffer     = array();
			$in_list    = false;
			return;
		}

		// Table rows.
		if ( preg_match( '/^\|/', $first ) ) {
			$output[] = $this->parse_table( $buffer );
			$buffer   = array();
			return;
		}

		// Blockquote lines.
		if ( preg_match( '/^&gt; /', $first ) ) {
			$text     = implode( "\n", array_map( function ( $l ) {
				return preg_replace( '/^&gt; /', '', $l );
			}, $buffer ) );
			$output[] = '<blockquote>' . $this->inline( $text ) . '</blockquote>';
			$buffer   = array();
			return;
		}

		// Regular paragraph.
		$text     = implode( '<br>', array_map( array( $this, 'inline' ), $buffer ) );
		$output[] = '<p>' . $text . '</p>';
		$buffer   = array();
	}

	/**
	 * Apply inline Markdown formatting.
	 *
	 * @param string $text Raw text line.
	 * @return string HTML with inline formatting.
	 */
	private function inline( $text ) {
		// Bold.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );

		// Italic.
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text );

		// Images (before links).
		$text = preg_replace( '/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="as-cai-prose-img">', $text );

		// Links.
		$text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text );

		return $text;
	}

	/**
	 * Parse a group of table rows into HTML.
	 *
	 * @param array $rows Raw table row lines.
	 * @return string Table HTML.
	 */
	private function parse_table( $rows ) {
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
				$output .= '<' . $tag . '>' . $this->inline( $cell ) . '</' . $tag . '>';
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
	}
}
