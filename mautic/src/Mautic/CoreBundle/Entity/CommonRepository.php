<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Mautic\CoreBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mautic\CoreBundle\Helper\SearchStringHelper;
use Mautic\UserBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Class CommonRepository
 *
 * @package Mautic\CoreBundle\Entity
 */
class CommonRepository extends EntityRepository
{

    /**
     * @var Translator
     */
    protected $translator;

    /**
     * @var User
     */
    protected $currentUser;

    public function setTranslator($translator)
    {
        if (!$translator instanceof Translator && !$translator instanceof IdentityTranslator) {
            throw new FatalErrorException();
        }
        $this->translator = $translator;
    }

    /**
     * Set the current user (i.e. from security context) for use within repositories
     *
     * @param User $user
     */
    public function setCurrentUser(User $user)
    {
        $this->currentUser = $user;
    }

    /**
     * Save an entity through the repository
     *
     * @param $entity
     * @return int
     */
    public function saveEntity($entity)
    {
        $this->_em->persist($entity);
        $this->_em->flush();
        return $entity;
    }

    /**
     * Delete an entity through the repository
     *
     * @param $entity
     * @return int
     */
    public function deleteEntity($entity)
    {
        //delete entity
        $this->_em->remove($entity);
        $this->_em->flush();
        return $entity;
    }

    protected function buildClauses(QueryBuilder &$q, array $args)
    {
        if (!$this->buildWhereClause($q, $args)) {
            return false;
        }
        $this->buildOrderByClause($q, $args);
        $this->buildLimiterClauses($q, $args);

        return true;
    }

    protected function buildWhereClause(QueryBuilder &$q, array $args)
    {
        $filter       = array_key_exists('filter', $args) ? $args['filter'] : '';
        $filterHelper = new SearchStringHelper();
        $string       = '';

        if (!empty($filter)) {
            if (is_array($filter)) {
                if (!empty($filter['force'])) {
                    if (is_array($filter['force'])) {
                        //defined columns with keys of column, expr, value
                        $forceParameters  = array();
                        $forceExpressions = $q->expr()->andX();
                        foreach ($filter['force'] as $f) {
                            $unique = $this->generateRandomParameterName();
                            $forceExpressions->add(
                                $q->expr()->{$f['expr']}($f['column'], $unique)
                            );
                            $forceParameters[$unique] = $f['value'];
                        }
                    } else {
                        //string so parse as advanced search
                        $parsed = $filterHelper->parseSearchString($filter['force']);
                        list($forceExpressions, $forceParameters) = $this->addAdvancedSearchWhereClause($q, $parsed);
                    }
                }

                if (!empty($filter['string'])) {
                    $string = $filter['string'];
                }
            } else {
                $string = $filter;
            }

            //remove wildcards passed by user
            if (strpos($string, '%') !== false) {
                $string = str_replace('%', '', $string);
            }

            $filter = $filterHelper->parseSearchString($string);

            list($expressions, $parameters) = $this->addAdvancedSearchWhereClause($q, $filter);

            if (!empty($forceExpressions)) {
                $expressions->add($forceExpressions);
                $parameters  = array_merge($parameters, $forceParameters);
            }
            $count = count($expressions->getParts());
            if (!empty($count)) {
                $q->where($expressions)
                    ->setParameters($parameters);
            } else {
                return false;
            }
        }
        return true;
    }

    protected function addCatchAllWhereClause(QueryBuilder &$qb, $filter)
    {
        return array(
            false,
            array()
        );
    }

    protected function addAdvancedSearchWhereClause(QueryBuilder &$qb, $filters)
    {
        if (isset($filters->root)) {
            //function is determined by the second clause type
            $type         = (isset($filters->root[1])) ? $filters->root[1]->type : $filters->root[0]->type;
            $parseFilters =& $filters->root;
        } elseif (isset($filters->children)) {
            $type         = (isset($filters->children[1])) ? $filters->children[1]->type : $filters->children[0]->type;
            $parseFilters =& $filters->children;
        } else {
            $type         = (isset($filters[1])) ? $filters[1]->type : $filters[0]->type;
            $parseFilters =& $filters;
        }

        $parameters  = array();
        $expressions = $qb->expr()->{"{$type}X"}();

        foreach ($parseFilters as $f) {
            if (isset($f->children)) {
                list($expr, $params) = $this->addAdvancedSearchWhereClause($qb, $f);
            } else {
                if (!empty($f->command)) {
                    if ($this->isSupportedSearchCommand($f->command, $f->string)) {
                        list($expr, $params) = $this->addSearchCommandWhereClause($qb, $f);
                    } else {
                        //treat the command:string as if its a single word
                        $f->string = $f->command . ":" . $f->string;
                        $f->not    = false;
                        $f->strict = true;
                        list($expr, $params) = $this->addCatchAllWhereClause($qb, $f);
                    }
                } else {
                    list($expr, $params) = $this->addCatchAllWhereClause($qb, $f);
                }
            }
            if (!empty($params))
                $parameters = array_merge($parameters, $params);

            if (!empty($expr))
                $expressions->add($expr);
        }
        return array($expressions, $parameters);
    }

    /**
     * Array of search commands supported by the repository
     *
     * @return array
     */
    public function getSearchCommands()
    {
        return array();
    }

    /**
     * Test to see if a given command is supported by the repository
     *
     * @param $command
     * @param $subcommand
     * @return bool
     */
    protected function isSupportedSearchCommand($command, $subcommand = '')
    {
        $commands = $this->getSearchCommands();
        foreach ($commands as $k => $c) {
            if (is_array($c)) {
                //subcommands
                if ($this->translator->trans($k) == $command) {
                    foreach ($c as $subc) {
                        if ($this->translator->trans($subc) == $subcommand) {
                            return true;
                        }
                    }
                }
            } elseif ($this->translator->trans($c) == $command) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param QueryBuilder $q
     * @param array        $args
     */
    protected function buildOrderByClause(QueryBuilder &$q, array $args)
    {
        $orderBy    = array_key_exists('orderBy', $args) ? $args['orderBy'] : $this->getDefaultOrderBy();
        $orderByDir = array_key_exists('orderByDir', $args) ? $args['orderByDir'] : "ASC";

        if (!empty($orderBy)) {
            $q->orderBy($orderBy, $orderByDir);
        }
    }

    protected function getDefaultOrderBy()
    {
        return '';
    }

    protected function buildLimiterClauses(QueryBuilder &$q, array $args)
    {
        $start      = array_key_exists('start', $args) ? $args['start'] : 0;
        $limit      = array_key_exists('limit', $args) ? $args['limit'] : 30;

        $q->setFirstResult($start)
            ->setMaxResults($limit);
    }

    protected function generateRandomParameterName()
    {
        $alpha_numeric = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        return substr(str_shuffle($alpha_numeric), 0, 8);
    }
}
