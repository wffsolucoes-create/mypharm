<?php
$d = json_decode(file_get_contents('api/cache/deals.json'), true);
foreach($d['deals'] as $x) {
    if(stripos($x['titulo'], 'Georgia')!==false || stripos($x['cliente'], 'Georgia')!==false) {
        print_r($x);
    }
}
