<?php
function convertLinksToHtml($text) {
    if (!$text) return '';

    // Unity-style <link="">
    $text = preg_replace_callback('/<link="(.*?)">(.*?)<\/link>/i', function ($m) {
        $url = htmlspecialchars_decode($m[1]);
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#3565A7;text-decoration:underline;">link</a>';
    }, $text);

    // Markdown-style [label](url)
    $text = preg_replace_callback('/\[(.*?)\]\((https?:\/\/[^)]+)\)/i', function ($m) {
        $label = trim($m[1]);
        $url = trim($m[2]);

        // se il testo tra [] è un URL → mostra "link"
        if (filter_var($label, FILTER_VALIDATE_URL)) {
            $label = 'link';
        }

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer" style="color:#3565A7;text-decoration:underline;">' 
               . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }, $text);

    return trim($text);
}

function formatEmails($text) {
    return preg_replace_callback('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', function ($m) {
        $email = $m[0];
        return '<a href="mailto:' . $email . '" style="color:#3565A7;text-decoration:underline;">' . $email . '</a>';
    }, $text);
}

function formatPhones($text) {
    return preg_replace_callback('/\+?\s*(?:\d[\s\-]*){7,}\d/', function ($m) {
        $phone = trim($m[0]);
        $compact = preg_replace('/[\s\-]+/', '', $phone);
        return '<a href="tel:' . $compact . '" style="color:#3565A7;text-decoration:underline;">' . $phone . '</a>';
    }, $text);
}

function cleanText($text) {
    $text = preg_replace(['/\\[\\d+\\]/', '/[ \\t]+/', '/\\s+([.,;:!?])/', '/\\s{2,}/'], ['', ' ', '$1', ' '], $text);
    return trim($text);
}

function formatElevenLabs($text) {
    $text = preg_replace('/\\[\\d+\\]/', '', $text);
    $text = preg_replace_callback('/\\[(.*?)\\]\\((.*?)\\)/', fn($m) => $m[1], $text);
    return trim(strip_tags($text));
}

function convertHtmlLinksToMarkdown($text) {
    return preg_replace_callback(
        '/<a\s+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i',
        function ($m) {
            $url = html_entity_decode(trim($m[1]));
            $label = strip_tags(trim($m[2]));
            if ($label === $url) {
                return '[' . $url . '](' . $url . ')';
            } else {
                return '[' . $label . '](' . $url . ')';
            }
        },
        $text
    );
}

function htmlToMarkdownClean($text) {
    $text = convertHtmlLinksToMarkdown($text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    return trim($text);
}

?> 