<?php
require("header.php"); // Important includes

// Configuration
$ES_URL = $CONFIG['URL']['ES'] . $CONFIG['PORT']['ES_PORT'];
$config = Array(
    "clickable" => false,
);

$nodes = file_get_contents("$ES_URL/_cluster/state/nodes");
if ($nodes === false) {
    error("No data returned from", "elasticsearch");
}
$nodes = json_decode($nodes, true);
$nodes = $nodes['nodes'];

// Add a fake node called "unassigned" so that unassigned shards are grouped on the display
$nodes['unassigned'] = Array(
    'name' => 'unassigned',
    'attributes' => Array(),
);

$health = file_get_contents("$ES_URL/_cluster/health/?level=cluster");
$health = json_decode($health, true);
$health = $health;

$cluster = file_get_contents("$ES_URL/_cluster/state/routing_table");
$cluster = json_decode($cluster, true);

$indices = $cluster['routing_table']['indices'];

$host_shards = Array();
foreach ($indices as $index_name => $index) {
    foreach ($index['shards'] as $shards) {
        foreach ($shards as $shard ) {
            $node = 'unassigned';
            if ($shard['node']) {
                $node = $shard['node'];
            }
            $shard['index'] = $index_name;
            if (!array_key_exists($node, $host_shards)) {
                $host_shards[$node] = Array();
            }
            array_push($host_shards[$node], $shard);
        }
    }
}

$results = Array();
foreach ($nodes as $node_id => $node) {
    if (array_key_exists('attributes', $node) && !array_key_exists('client', $node['attributes'])) {

        $node_name = $node['name'];
        if (!array_key_exists($node_name, $results)) {
            $results[$node_name] = Array();
        }

        if (array_key_exists($node_id, $host_shards)) {

            foreach ($host_shards[$node_id] as $shard) {
                if (isset($shard['state']) && $shard['state'] != 'RELOCATING') {
                    unset($shard['relocating_node']);
                }

                $index_name = $shard['index'];
                $shard_info = Array();

                $status = Array();
                if ($shard['primary']) {
                    $shard_info['type'] = 'primary';
                    $status[$shard['state']] = $cluster['cluster_name'];

                } else {
                    $shard_info['type'] = 'replica';
                    $status[$shard['state'].' replica'] = $cluster['cluster_name'];
                }
                unset($shard['state']);

                foreach ($shard as $key => $value) {
                    // If this property looks like a node ID, look it up and replace it with the hostname of the node
                    if (isset($value) && strpos($key, 'node') !== false) {
                        $value = $nodes[$value]['name'];
                    }
                    $value = bool2str($value);
                    $shard_info[$key] = $value;
                }

                $shard_id = $shard_info['shard'];
                unset($shard_info['shard']);

                $results[$node_name]['']["${index_name}_shard${shard_id}"] = $shard_info;
                $results[$node_name]['']["${index_name}_shard${shard_id}"]['status'] = $status;
            }
        }
    }
}

ksort($results);

$groups = Array(
    $cluster['cluster_name'] => $results,
);
$groups['config'] = $config;


echo json_encode($groups);
