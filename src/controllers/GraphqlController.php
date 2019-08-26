<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\controllers;

use Craft;
use craft\errors\GqlException;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\models\GqlSchema;
use craft\web\assets\graphiql\GraphiQlAsset;
use craft\web\Controller;
use craft\web\Request;
use GraphQL\GraphQL;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

Craft::$app->requireEdition(Craft::Pro);

/**
 * The GqlController class is a controller that handles various GraphQL related tasks.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.3.0
 */
class GraphqlController extends Controller
{
    /**
     * @inheritdoc
     */
    public $allowAnonymous = ['api'];

    /**
     * @inheritdoc
     */
    public $defaultAction = 'api';

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        if ($action->id === 'api') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Performs a GraphQL query.
     *
     * @return Response
     * @throws BadRequestHttpException
     * @throws GqlException
     */
    public function actionApi(): Response
    {
        Craft::$app->getResponse()->format = Response::FORMAT_JSON;

        $gqlService = Craft::$app->getGql();
        $request = Craft::$app->getRequest();

        $schema = null;
        $authorizationHeader = Craft::$app->request->headers->get('authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            $accessToken = $matches[1];
            $schema = $gqlService->getSchemaByAccessToken($accessToken);
        }

        // What if something already set it on the service?
        if (!$schema) {
            try {
                $schema = $gqlService->getActiveSchema();
            } catch (GqlException $exception) {
                // Well, go for the public schema then.
                $schema = $gqlService->getPublicSchema();
            }
        }

        $schemaExpired = $schema && $schema->expiryDate && $schema->expiryDate->getTimestamp() <= DateTimeHelper::currentTimeStamp();

        if (!$schema || !$schema->enabled || $schemaExpired) {
            throw new ForbiddenHttpException('Invalid authorization token.');
        }

        $query = $operationName = $variables = null;

        // Check the body if it's a POST request
        if ($request->getIsPost()) {
            // If it's a application/graphql request, the whole body is the query
            if ($request->getContentType() === 'application/graphql') {
                $query = $request->getRawBody();
            } else {
                $query = $request->getBodyParam('query');
                $operationName = $request->getBodyParam('operationName');
                $variables = $request->getBodyParam('variables');
            }
        }

        // 'query' GET param supersedes all others though
        $query = $request->getQueryParam('query', $query);

        // 400 error if we couldn't find the query
        if ($query === null) {
            throw new BadRequestHttpException('No GraphQL query was supplied.');
        }

        try {
            $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
            $schemaDef = $gqlService->getSchemaDef($schema, $devMode);
            $result = GraphQL::executeQuery($schemaDef, $query, null, null, $variables, $operationName)
                ->toArray(true);
        } catch (\Throwable $e) {
            throw new GqlException('Something went wrong when processing the GraphQL query.', 0, $e);
        }

        return $this->asJson($result);
    }

    /**
     * @return Response
     * @throws ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionGraphiql(): Response
    {
        $this->requireAdmin();
        $this->getView()->registerAssetBundle(GraphiQlAsset::class);

        $schemaUid = Craft::$app->getRequest()->getQueryParam('schemaUid');
        $gqlService = Craft::$app->getGql();

        if (!$schemaUid) {
            $selectedSchema = $gqlService->getPublicSchema();
        } else {
            $selectedSchema = $gqlService->getSchemaByUid($schemaUid);
        }

        if (!$selectedSchema) {
            $selectedSchema = $gqlService->getPublicSchema();
        }

        $schemas = [];

        foreach ($gqlService->getSchemas() as $schema) {
            $name = $schema->getIsPublic() ? Craft::t('app', 'Public Schema') : $schema->name;
            $schemas[$name] = $schema->uid;
        }

        return $this->renderTemplate('graphql/graphiql', [
            'url' => UrlHelper::actionUrl('graphql/api'),
            'schemas' => $schemas,
            'selectedSchema' => $selectedSchema
        ]);
    }

    /**
     * @return Response
     * @throws ForbiddenHttpException
     */
    public function actionViewSchemas(): Response
    {
        $this->requireAdmin();
        return $this->renderTemplate('graphql/schemas/_index');
    }

    /**
     * @param int|null $schemaId
     * @param GqlSchema|null $schema
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionEditSchema(int $schemaId = null, GqlSchema $schema = null): Response
    {
        $this->requireAdmin();

        $gqlService = Craft::$app->getGql();

        if ($schema || $schemaId) {
            if (!$schema) {
                $schema = $gqlService->getSchemaById($schemaId);
            }

            if (!$schema) {
                throw new NotFoundHttpException('Schema not found');
            }

            if ($schema->getIsPublic()) {
                $title = Craft::t('app', 'Edit the Public GraphQL Schema');
            } else {
                $title = trim($schema->name) ?: Craft::t('app', 'Edit GraphQL Schema');
            }
        } else {
            $schema = new GqlSchema();
            $schema->accessToken = Craft::$app->getSecurity()->generateRandomString(32);
            $title = trim($schema->name) ?: Craft::t('app', 'Create a new GraphQL schema');
        }

        return $this->renderTemplate('graphql/schemas/_edit', compact(
            'schema',
            'title'
        ));
    }

    /**
     * @return Response|null
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\Exception
     */
    public function actionSaveSchema()
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $gqlService = Craft::$app->getGql();
        $request = Craft::$app->getRequest();

        $schemaId = $request->getBodyParam('schemaId');

        if ($schemaId) {
            $schema = $gqlService->getSchemaById($schemaId);

            if (!$schema) {
                throw new NotFoundHttpException('Schema not found');
            }
        } else {
            $schema = new GqlSchema();
            $schema->accessToken = $request->getRequiredBodyParam('accessToken');
        }

        $schema->name = $request->getBodyParam('name') ?? $schema->name;
        $schema->enabled = (bool)$request->getRequiredBodyParam('enabled');
        $schema->scope = $request->getBodyParam('permissions');

        if (($expiryDate = $request->getBodyParam('expiryDate')) !== null) {
            $schema->expiryDate = DateTimeHelper::toDateTime($expiryDate) ?: null;
        }

        $session = Craft::$app->getSession();

        if (!$gqlService->saveSchema($schema)) {
            $session->setError(Craft::t('app', 'Couldn’t save schema.'));

            // Send the volume back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'schema' => $schema
            ]);

            return null;
        }

        $session->setNotice(Craft::t('app', 'Schema saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionDeleteSchema(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $schemaId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        Craft::$app->getGql()->deleteSchemaById($schemaId);

        return $this->asJson(['success' => true]);
    }
}
