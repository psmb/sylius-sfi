<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20190911141547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE sylius_order ADD postomat VARCHAR(255) DEFAULT NULL, CHANGE shipping_address_id shipping_address_id INT DEFAULT NULL, CHANGE billing_address_id billing_address_id INT DEFAULT NULL, CHANGE channel_id channel_id INT DEFAULT NULL, CHANGE promotion_coupon_id promotion_coupon_id INT DEFAULT NULL, CHANGE customer_id customer_id INT DEFAULT NULL, CHANGE number number VARCHAR(255) DEFAULT NULL, CHANGE checkout_completed_at checkout_completed_at DATETIME DEFAULT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL, CHANGE token_value token_value VARCHAR(255) DEFAULT NULL, CHANGE customer_ip customer_ip VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        $this->addSql('ALTER TABLE sylius_order DROP postomat, CHANGE channel_id channel_id INT DEFAULT NULL, CHANGE promotion_coupon_id promotion_coupon_id INT DEFAULT NULL, CHANGE customer_id customer_id INT DEFAULT NULL, CHANGE shipping_address_id shipping_address_id INT DEFAULT NULL, CHANGE billing_address_id billing_address_id INT DEFAULT NULL, CHANGE number number VARCHAR(255) DEFAULT \'NULL\' COLLATE utf8_unicode_ci, CHANGE checkout_completed_at checkout_completed_at DATETIME DEFAULT \'NULL\', CHANGE updated_at updated_at DATETIME DEFAULT \'NULL\', CHANGE token_value token_value VARCHAR(255) DEFAULT \'NULL\' COLLATE utf8_unicode_ci, CHANGE customer_ip customer_ip VARCHAR(255) DEFAULT \'NULL\' COLLATE utf8_unicode_ci');
    }
}
