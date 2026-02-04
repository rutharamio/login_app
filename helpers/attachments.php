<?php

function normalizeAttachments(array $files): array
{
    $normalized = [];

    if (empty($files) || empty($files['name']) || !is_array($files['name'])) {
        return [];
    }

    foreach ($files['name'] as $i => $name) {

        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $normalized[] = [
            'name'     => $name,
            'type'     => $files['type'][$i] ?? 'application/octet-stream',
            'tmp_name' => $files['tmp_name'][$i],
            'size'     => $files['size'][$i] ?? 0,
        ];
    }

    return $normalized;
}
