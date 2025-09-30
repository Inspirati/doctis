<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Fetch Lorem Ipsum (tries remote services, then falls back to local generator).
 *
 * @param int $max_bytes Maximum number of bytes to return (0 => empty).
 * @param int $paras    Number of paragraphs to request (default 5).
 * @return string       Lorem Ipsum text truncated to $max_bytes bytes (UTF-8 safe when possible).
 */
function get_lorem_ipsum( int $max_bytes, int $paras = 5 ): string {
    if ( $max_bytes <= 0 ) {
        return '';
    }

    // endpoints to try (BaconIpsum supports HTTPS; Loripsum is HTTP-only)
    $endpoints = [
        "https://baconipsum.com/api/?type=meat-and-filler&paras={$paras}&format=text",
        // Loripsum tends to be HTTP-only; still useful as a fallback
        "http://loripsum.net/api/{$paras}/plaintext",
        // small alternative service (if available) — uncomment/edit if you prefer:
        // "https://lorem-api.com/api/..." 
    ];

    foreach ( $endpoints as $url ) {
        $body = lorem_fetch_url( $url, 5 );
        if ( $body !== false ) {
            $body = trim( $body );
            if ( $body !== '' ) {
                return lorem_truncate_bytes( $body, $max_bytes );
            }
        }
    }

    // Last resort: local generated paragraphs (guaranteed to work offline)
    $local = lorem_generate_local( $paras );
    return lorem_truncate_bytes( $local, $max_bytes );
}

/* --- helpers --- */

function lorem_fetch_url( string $url, int $timeout = 5 ) {
    // prefer cURL
    if ( function_exists( 'curl_version' ) ) {
        $ch = curl_init( $url );
        curl_setopt_array( $ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PHP LoremFetcher/1.0',
            CURLOPT_ENCODING => '', // accept gzip
        ]);
        $data = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );
        if ( $data !== false && $code >= 200 && $code < 300 ) {
            return $data;
        }
        return false;
    }

    // fallback to file_get_contents (may be disabled on some hosts)
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => 'PHP LoremFetcher/1.0'],
        'https' => ['timeout' => $timeout, 'user_agent' => 'PHP LoremFetcher/1.0'],
    ]);
    $data = @file_get_contents( $url, false, $ctx );
    return $data === false ? false : $data;
}

/**
 * Truncate a string to at most $max_bytes bytes while preserving UTF-8 validity when possible.
 */
function lorem_truncate_bytes( string $text, int $max_bytes ): string {
    if ( strlen( $text ) <= $max_bytes ) {
        return $text;
    }

    // Best: mb_strcut (cuts by bytes but preserves valid multibyte characters)
    if ( function_exists( 'mb_strcut' ) ) {
        return mb_strcut( $text, 0, $max_bytes, 'UTF-8' );
    }

    // Fallback: naive cut + attempt to remove broken tail bytes
    $cut = substr( $text, 0, $max_bytes );

    // If mb_check_encoding is available, drop trailing bytes until valid UTF-8
    if ( function_exists( 'mb_check_encoding' ) ) {
        while ( $cut !== '' && !mb_check_encoding( $cut, 'UTF-8' ) ) {
            $cut = substr( $cut, 0, -1 );
        }
        return $cut;
    }

    // Worst case: return raw substring (may break a multibyte char)
    return $cut;
}

/**
 * Very small local lorem generator (used when remote services fail).
 */
function lorem_generate_local( int $paras ): string {
    // A reasonably sized paragraph (public domain Lorem Ipsum)
    $sample = "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. "
            . "Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. "
            . "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.";

    $out = [];
    for ( $i = 0; $i < max(1, $paras); $i++ ) {
        // Slightly vary paragraphs so they don't look identical:
        $out[] = $sample . ' (' . ($i+1) . ')';
    }
    return implode( "\n\n", $out );
}

/**
 * Generate randomized Lorem Ipsum text with randomized length.
 *
 * @param int $min_length  Minimum length of the text (bytes).
 * @param int $max_length  Maximum length of the text (bytes).
 * @return string          Generated Lorem Ipsum text.
 */
function generate_random_lorem($min_length = 100, $max_length = 500) {
    // Base Lorem Ipsum sentences
    $sentences = [
        "Lorem ipsum dolor sit amet, consectetur adipiscing elit.",
        "Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.",
        "Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.",
        "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.",
        "Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.",
        "Curabitur pretium tincidunt lacus.",
        "Nulla gravida orci a odio.",
        "Nullam varius, turpis et commodo pharetra, est eros bibendum elit, nec luctus magna felis sollicitudin mauris.",
        "Integer in mauris eu nibh euismod gravida.",
        "Aliquam erat volutpat.",
    ];

    // Normalize whitespace
    $sentences = array_map('trim', $sentences);

    // Decide random target length
    $target_length = rand($min_length, $max_length);

    // Shuffle repeatedly and append until length reached
    $output = '';
    while (strlen($output) < $target_length) {
        shuffle($sentences);
        $output .= ' ' . implode(' ', $sentences);
    }

    // Trim to target length, but don’t cut off mid-word
    $output = substr($output, 0, $target_length);
    $output = preg_replace('/\s+\S*$/', '', $output); // trim partial word at end

    return trim($output);
}


/**
 * Return a random Winnie-the-Pooh related book title.
 *
 * @return string
 */
function random_publication_title() {
    $titles = [
        "Winnie-the-Pooh",
        "The House at Pooh Corner",
        "Now We Are Six",
        "When We Were Very Young",
        "Return to the Hundred Acre Wood",
        "The Best Bear in All the World",
        "Pooh and Piglet Go Hunting",
        "Eeyore Loses a Tail",
        "Pooh Invents a New Game",
        "The Tao of Pooh",
        "The Te of Piglet",
        "The World of Pooh"
    ];

    return $titles[array_rand($titles)];
}

/**
 * Return a random author name associated with Winnie-the-Pooh or related works.
 *
 * @return string
 */
function random_author_name() {
    $authors = [
        "A. A. Milne",         // Original Pooh stories
        "E. H. Shepard",       // Illustrator
        "David Benedictus",    // 'Return to the Hundred Acre Wood'
        "Jeanne Willis",       // Contributed to 'The Best Bear in All the World'
        "Kate Saunders",       // Ditto
        "Brian Sibley",        // Pooh historian/adaptor
        "Paul Bright",         // Ditto
        "Benjamin Hoff"        // 'The Tao of Pooh', 'The Te of Piglet'
    ];

    return $authors[array_rand($authors)];
}

function random_numeric() {
	$length = random_int(1, 2);
	$digits = '';
	for( $i = 0; $i < $length; $i++ ) {
		$digits .= random_int(0, 9);
	}
	return $digits;
}

function random_numeric_string() {
	$length = random_int(5, 15);
	$digits = '';
	for( $i = 0; $i < $length; $i++ ) {
		$digits .= random_int(0, 9);
	}
	return $digits;
}

function random_reference() {
	$digits = 'AB';
	for( $i = 0; $i < 8; $i++ ) {
		$digits .= random_int(0, 9);
	}
	return $digits;
}

