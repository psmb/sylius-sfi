<?php

namespace App;

class SortExtension extends \Twig_Extension
{
    public function getName()
    {
        return 'sortarrow';
    }
    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('sortarrow', array($this, 'sortArrowFilter'))
        );
    }

    function sortArrowFilter($array, $arrow = null)
    {
        if ($array instanceof \Traversable) {
            $array = iterator_to_array($array);
        } elseif (!\is_array($array)) {
            throw new \Error(sprintf('The sort filter only works with arrays or "Traversable", got "%s".', \gettype($array)));
        }
        if (null !== $arrow) {
            uasort($array, $arrow);
        } else {
            asort($array);
        }
        return $array;
    }
}
