<?php

declare(strict_types=1);

namespace App\Repository;

use Psr\Log\LoggerInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\UserRepository as BaseUserRepository;
use Sylius\Component\User\Model\UserInterface;

class UserRepository extends BaseUserRepository
{
    private LoggerInterface $logger;

    public function __construct($entityManager, $class, LoggerInterface $logger = null)
    {
        parent::__construct($entityManager, $class);
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function findOneByEmail(string $email): ?UserInterface
    {
        // Log to verify custom repository is being used
        if ($this->logger) {
            $this->logger->info('Custom UserRepository::findOneByEmail called', ['email' => $email]);
        }

        // Custom implementation - using customer.emailCanonical instead of user.email
        return $this->createQueryBuilder('o')
            ->innerJoin('o.customer', 'customer')
            ->andWhere('customer.emailCanonical = :email')
            ->setParameter('email', strtolower($email))
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
