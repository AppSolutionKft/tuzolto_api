<?php
namespace App\core\interfaces;

interface CRUDControllerInterface
{
    public function create();
    public function get();
    public function update();
    public function delete();
}