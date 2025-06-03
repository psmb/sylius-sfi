<?php

declare(strict_types=1);

namespace App\Repository;

use Sylius\Bundle\CoreBundle\Doctrine\ORM\UserRepository as BaseUserRepository;
use Sylius\Component\User\Model\UserInterface;

class UserRepository extends BaseUserRepository
{
    /**
     * {@inheritdoc}
     */
    public function findOneByEmail(string $email): ?UserInterface
    {
        // Log to verify custom repository is being used
        error_log('CUSTOM UserRepository::findOneByEmail called with email: ' . $email);

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
