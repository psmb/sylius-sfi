<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Sylius\Component\User\Repository\UserRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserCheckController
{
    /**
     * @var UserRepositoryInterface
     */
    private $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function checkAction(Request $request): JsonResponse
    {
        $email = $request->query->get('email');

        error_log('CUSTOM UserCheckController::checkAction called with email: ' . $email);

        if (!$email) {
            return new JsonResponse(['exists' => false]);
        }

        $user = $this->userRepository->findOneByEmail($email);

        if ($user) {
            return new JsonResponse([
                'username' => $user->getEmail()
            ]);
        }

        return new JsonResponse([]);
    }
}
