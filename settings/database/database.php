<?php
        class Database {
                private static $conn = null;

                public static function getConnection() {
                        if (self::$conn === null) {
                                $host = 'localhost';

                                $dbname = 'inventory';
                                $username = 'root';
                                $password = '';

                                self::$conn = mysqli_connect($host, $username, $password, $dbname);

                                if (!self::$conn) {
                                        die("Connection failed: " . mysqli_connect_error());
                                }

                                mysqli_set_charset(self::$conn, "utf8");
                        }

                        return self::$conn;
                }
        }
?>