<?php
// balance.php

// This file manages user balances in the system.

class UserBalance {
    private $balance;

    public function __construct() {
        $this->balance = 0;
    }

    public function addFunds($amount) {
        $this->balance += $amount;
    }

    public function deductFunds($amount) {
        if ($this->balance >= $amount) {
            $this->balance -= $amount;
        } else {
            throw new Exception('Insufficient balance.');
        }
    }

    public function getBalance() {
        return $this->balance;
    }
}

?>