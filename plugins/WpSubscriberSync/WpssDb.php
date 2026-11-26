<?php
/**
 * Minimal prepared-statement helper on top of phpList's mysqli connection.
 *
 * phpList's Sql_* functions build queries with sprintf() and print HTML on
 * errors; the endpoint needs real parameter binding and exceptions instead.
 *
 * @license AGPL-3.0-or-later
 */

defined('PHPLISTINIT') || exit;

class WpssDbException extends RuntimeException
{
}

class WpssDb
{
    /** @var mysqli */
    private $link;

    public function __construct($link = null)
    {
        if ($link === null) {
            if (empty($GLOBALS['database_connection'])) {
                // Let phpList open its connection the usual way.
                Sql_Query('select 1');
            }
            $link = $GLOBALS['database_connection'];
        }
        if (!$link instanceof mysqli) {
            throw new WpssDbException('No database connection');
        }
        $this->link = $link;
    }

    /**
     * Run a statement. Parameters are bound as strings unless they are int.
     *
     * @return mysqli_stmt
     */
    public function run($sql, array $params = array())
    {
        try {
            $stmt = $this->link->prepare($sql);
            if ($stmt === false) {
                throw new WpssDbException('Prepare failed: '.$this->link->error);
            }
            if ($params) {
                $types = '';
                $values = array();
                foreach ($params as $p) {
                    $types .= is_int($p) ? 'i' : 's';
                    $values[] = $p === null ? null : (is_int($p) ? $p : (string) $p);
                }
                $stmt->bind_param($types, ...$values);
            }
            if (!$stmt->execute()) {
                throw new WpssDbException('Execute failed: '.$stmt->error);
            }

            return $stmt;
        } catch (mysqli_sql_exception $e) {
            throw new WpssDbException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /** All rows as associative arrays. */
    public function all($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        $res = $stmt->get_result();
        $rows = array();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();

        return $rows;
    }

    /** First row or null. */
    public function one($sql, array $params = array())
    {
        $rows = $this->all($sql, $params);

        return $rows ? $rows[0] : null;
    }

    /** Execute and return affected rows. */
    public function exec($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        $n = $stmt->affected_rows;
        $stmt->close();

        return $n;
    }

    public function insertId()
    {
        return (int) $this->link->insert_id;
    }
}
