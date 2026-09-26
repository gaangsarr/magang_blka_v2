<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmailQueue extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('email_queue');
        $table->addColumn('to_email', 'string', ['limit' => 255])
              ->addColumn('to_name', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('subject', 'string', ['limit' => 255])
              ->addColumn('body_html', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
              ->addColumn('body_text', 'text', ['null' => true])
              ->addColumn('status', 'enum', [
                  'values' => ['pending', 'processing', 'sent', 'failed'],
                  'default' => 'pending'
              ])
              ->addColumn('attempts', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 0])
              ->addColumn('max_attempts', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 3])
              ->addColumn('last_error', 'text', ['null' => true])
              ->addColumn('sent_at', 'datetime', ['null' => true])
              ->addTimestamps()
              ->addIndex(['status', 'created_at'])
              ->create();
    }
}
