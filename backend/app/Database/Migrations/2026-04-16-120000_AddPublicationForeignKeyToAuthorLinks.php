<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPublicationForeignKeyToAuthorLinks extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();

        // Remove orphaned author-link rows first so the foreign key can be added safely.
        $db->query('
            DELETE pal
            FROM publication_author_links pal
            LEFT JOIN publications p ON p.id = pal.publication_id
            WHERE p.id IS NULL
        ');

        $this->forge->addForeignKey(
            'publication_id',
            'publications',
            'id',
            'CASCADE',
            'CASCADE',
            'fk_publication_author_links_publication_id'
        );
        $this->forge->processIndexes('publication_author_links');
    }

    public function down()
    {
        $this->forge->dropForeignKey('publication_author_links', 'fk_publication_author_links_publication_id');
    }
}
