<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class UserCheckController
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
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
