<?php
// Menu entries for the OLED Boot Logo plugin (FPP includes this per menu section).
$menuEntries = Array(
    Array('text' => 'OLED Boot Logo', 'type' => 'content', 'page' => 'plugin.php', 'wrap' => 1),
    Array('text' => 'OLED Boot Logo - Help', 'type' => 'help', 'page' => 'https://github.com/joeskolengaden/oledlogo', 'wrap' => 1)
);
foreach ($menuEntries as $entry) {
    if ($entry['type'] != $menu) continue;
    if (preg_match('/^http.?:\/\//', $entry['page']))
        printf("<li><a href='%s' target='_blank'>%s</a></li>\n", $entry['page'], $entry['text']);
    else {
        $nopage = (isset($entry['wrap']) && $entry['wrap'] == 0) ? '&nopage=1' : '';
        printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n", $plugin, $entry['page'], $nopage, $entry['text']);
    }
}
?>
