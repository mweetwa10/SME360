<?php

function generateId(PDO $pdo, string $table, string $column, string $prefix): string
{
    $sql = "SELECT $column 
            FROM $table 
            WHERE $column LIKE :prefix
            ORDER BY $column DESC
            LIMIT 1";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':prefix' => $prefix . '%'
    ]);

    $lastId = $stmt->fetchColumn();

    if ($lastId) {

        $number = intval(
            substr($lastId, strlen($prefix))
        );

        $number++;

    } else {

        $number = 1;

    }

    return $prefix . str_pad(
        $number,
        4,
        '0',
        STR_PAD_LEFT
    );
}