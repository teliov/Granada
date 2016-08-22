<?php
/**
 * Created by PhpStorm.
 * User: teliov
 * Date: 8/22/16
 * Time: 3:49 PM
 */

namespace Granada;

use JsonSerializable;
use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

class Paginator extends ResultSet
{
    /**
     * @var integer
     */
    protected $total;

    /**
     * @var integer
     */
    protected $perPage;

    /**
     * @var integer
     */
    protected $currentPage;

    /**
     * @var integer
     */
    protected $lastPage;

    public function __construct($items, $total, $perPage, $currentPage)
    {
        parent::__construct($items);

        $this->total = $total;
        $this->perPage= $perPage;
        $this->currentPage = $currentPage;
        $this->lastPage = ceil($total/$perPage);
    }

    public function hasNext()
    {
        return $this->currentPage < $this->lastPage;
    }

    public function getNextPage()
    {
        return $this->hasNext() ? $this->currentPage + 1: null;
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function getCurrentPage()
    {
        return $this->currentPage;
    }

    public function getPerPage()
    {
        return $this->perPage;
    }

    public function getLastPage()
    {
        return $this->lastPage;
    }

    public function getPaginationArray()
    {
        return [
            "data" => $this->get_results(),
            "total" => $this->getTotal(),
            "page" => $this->getCurrentPage(),
            "next_page" => $this->getNextPage(),
            "per_page" => $this->getPerPage(),
            "last_page" => $this->getLastPage()
        ];
    }

    function jsonSerialize()
    {
        return $this->getPaginationArray();
    }
}