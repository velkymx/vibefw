<?php

declare(strict_types=1);

use Fw\Database\Migration\Migration;

final class AddRememberTokenToUsers extends Migration
{
    public function up(): void
    {
        $table = $this->quote('users');
        $column = $this->quote('remember_token');
        $this->execute("ALTER TABLE $table ADD COLUMN $column VARCHAR(255) NULL");
    }

    public function down(): void
    {
        if ($this->db->driver === 'sqlite') {
            // SQLite doesn't support DROP COLUMN easily, so we recreate the table
            $this->execute('
                CREATE TABLE "users_new" (
                    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                    "name" VARCHAR(255),
                    "email" VARCHAR(255) UNIQUE,
                    "password" VARCHAR(255),
                    "created_at" DATETIME DEFAULT CURRENT_TIMESTAMP,
                    "updated_at" DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $this->execute('INSERT INTO "users_new" SELECT id, name, email, password, created_at, updated_at FROM "users"');
            $this->execute('DROP TABLE "users"');
            $this->execute('ALTER TABLE "users_new" RENAME TO "users"');
        } else {
            $table = $this->quote('users');
            $column = $this->quote('remember_token');
            $this->execute("ALTER TABLE $table DROP COLUMN $column");
        }
    }
}
