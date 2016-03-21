<?php
/**
 * Author: Nil Portugués Calderó <contact@nilportugues.com>
 * Date: 11/27/15
 * Time: 7:47 PM.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NilPortugues\Laravel5\JsonApi\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use NilPortugues\Api\JsonApi\Http\Factory\RequestFactory;
use NilPortugues\Laravel5\JsonApi\JsonApiSerializer;

/**
 * Class EloquentHelper.
 */
trait EloquentHelper
{
    /**
     * @param JsonApiSerializer $serializer
     * @param Builder           $builder
     *
     * @return Builder
     */
    public static function paginate(JsonApiSerializer $serializer, Builder $builder)
    {
        self::filter($serializer, $builder, $builder->getModel());
        self::sort($serializer, $builder, $builder->getModel());

        $request = RequestFactory::create();

        $builder->paginate(
            $request->getPage()->size(),
            self::columns($serializer, $request->getFields()->get()),
            'page',
            $request->getPage()->number()
        );

        return $builder;
    }

    /**
     * @param JsonApiSerializer $serializer
     * @param Builder           $builder
     * @param Model             $model
     *
     * @return Builder
     */
    protected static function sort(JsonApiSerializer $serializer, Builder $builder, Model $model)
    {
        $mapping = $serializer->getTransformer()->getMappingByClassName(get_class($model));
        $sorts = RequestFactory::create()->getSort()->sorting();

        if (!empty($sorts)) {
            $aliased = $mapping->getAliasedProperties();

            $sortsFields = str_replace(array_values($aliased), array_keys($aliased), array_keys($sorts));
            $sorts = array_combine($sortsFields, array_values($sorts));

            foreach ($sorts as $field => $direction) {
                $builder->orderBy($field, ($direction === 'ascending') ? 'ASC' : 'DESC');
            }
        }

        return $builder;
    }

    /**
     * @param JsonApiSerializer $serializer
     * @param Builder           $builder
     * @param Model             $model
     *
     * @return Builder
     */
    protected static function filter(JsonApiSerializer $serializer, Builder $builder, Model $model)
    {
        $modelClass = strtolower(get_class($model));
        $requestFilters = RequestFactory::create()->getFilters();
        $filters = [];

        // normalize filters to:
        // ['model'] => [
        //      field => value,
        //      field => value
        // ],
        // ['othermodel'] => [
        //      field => value
        // ]
        foreach($requestFilters as $key => $value)
        {
            // index 0: model
            // index 1: field
            $field = explode('.', $key);

            // if there is no model name included, we will put the filter on the current model
            if( ! isset($field[1]) ) {
                $field[1] = $field[0];
                $field[0] = $modelClass;
            }

            // there could be multiple filters, so put this in an array
            if( ! isset($filters[$field[0]]) ) {
                $filters[$field[0]] = [];
            }

            $filters[$field[0]][$field[1]] = $value;
        }

        // if we have any filters
        if (!empty($filters)) {
            // go on and apply them
            foreach ($filters as $modelToFilter => $fields) {
                foreach($fields as $field => $value)
                {
                    // if the modelToFilter is not the same as the model this query originates on...
                    if($modelToFilter != $modelClass) {
                        // .. we're going to use whereHas
                        $builder->whereHas($table, function($query) use($field, $value) {
                            $query->where($field, '=', $value);
                        });
                    }
                    else {
                        // .. else we will query the originatin model
                        $builder->where($field, '=', $value);
                    }
                }
            }
        }

        return $builder;
    }

    /**
     * @param JsonApiSerializer $serializer
     * @param array             $fields
     *
     * @return array
     */
    protected static function columns(JsonApiSerializer $serializer, array $fields)
    {
        $filterColumns = [];

        foreach ($serializer->getTransformer()->getMappings() as $mapping) {
            $classAlias = $mapping->getClassAlias();

            if (!empty($fields[$classAlias])) {
                $className = $mapping->getClassName();
                $aliased = $mapping->getAliasedProperties();

                /** @var \Illuminate\Database\Eloquent\Model $model * */
                $model = new $className();
                $columns = $fields[$classAlias];

                if (count($aliased) > 0) {
                    $columns = str_replace(array_values($aliased), array_keys($aliased), $columns);
                }

                foreach ($columns as &$column) {
                    $filterColumns[] = sprintf('%s.%s', $model->getTable(), $column);
                }
                $filterColumns[] = sprintf('%s.%s', $model->getTable(), $model->getKeyName());
            }
        }

        return (count($filterColumns) > 0) ? $filterColumns : ['*'];
    }
}
