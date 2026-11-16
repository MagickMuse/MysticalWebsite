<?php
/*
 * Which parts of the Notion Manuscript the importer brings over, and in what order they're listed.
 * slug (used in the wiki's addresses) => Notion page id (the 32 characters at the end of its Notion link).
 * Not secret: an id only works with the Notion key, which lives in the private settings.
 * To add a grimoire, add a line and run the import.
 */
return [
    'begin-here'     => '2a516dcf7164804bb461c1a91571d60d',
    'correspondence' => '28216dcf716480e3918dfab86e4c0b1c',
    'timing'         => '28216dcf71648045b6e8cabf80242cb5',
    // 'plant'        => '28216dcf71648075856dd4f77f6fce89',
    // 'astrology'    => '28216dcf716480f9a3ced0b72e30a2a9',
    // 'animal'       => '28216dcf7164801cad82efa4a4e25424',
    // 'recipe'       => '28216dcf7164803eb959d824f8b44fb3',
    // 'card'         => '28216dcf716480299d34f1ca3f2e7b79',
    // 'entity'       => '28216dcf716480028b0deb9a262d6293',
    // 'mineral'      => '28216dcf716480cb8c5de9295a33fed2',
    // 'oil'          => '28216dcf716480bca5c7e298a9bd6ced',
    // 'ritual'       => '28216dcf71648077b48ff279b693dde7',
    // 'symbol'       => '28216dcf7164802dbcc9cce156e600c8',
];
