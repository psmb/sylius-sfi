<?php

namespace App;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class MenuListener
{
    public function addMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $menu
            ->addChild('new', ['route' => 'app_shop_download_list'])
            ->setLabel('Электронные товары')
            ->setLabelAttribute('icon', 'download');
    }
}
