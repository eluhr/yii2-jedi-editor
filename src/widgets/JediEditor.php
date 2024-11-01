<?php

namespace eluhr\jedi\widgets;

use eluhr\jedi\assets\JediAsset;
use stdClass;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Html;
use yii\helpers\Inflector;
use yii\helpers\Json;
use yii\web\JsExpression;
use yii\widgets\InputWidget;

/**
 * @property-write stdClass|string|array $schema
 */
class JediEditor extends InputWidget
{
    /**
     * @var array the HTML attributes for the textarea container tag.
     * @see \yii\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public array $containerOptions = [];

    /**
     * A json that contains the schema to build the form. Values can be given as array, string or stdClass
     * Does not to be set if pluginOptions property has schema set.
     */
    protected array $_schema;

    /**
     * Options to be passed to the jedi.
     * List of valid options can be found here:
     * https://github.com/germanbisurgi/jedi?tab=readme-ov-file#options
     */
    public array $pluginOptions = [];

    /**
     * @inheritdoc
     * @throw yii\base\InvalidConfigException if some config is not as expected
     */
    public function init()
    {
        parent::init();

        // If schema is set in plugin options use it from there
        if (isset($this->pluginOptions['schema'])) {
            $this->setSchema($this->pluginOptions['schema']);
            unset($this->pluginOptions['schema']);
        }

        $this->ensurePluginOptions();

        if (!isset($this->_schema)) {
            throw new InvalidConfigException("Property 'schema' must be specified.");
        }

        // Always set a unique id for the container
        if (!isset($this->containerOptions['id'])) {
            $this->containerOptions['id'] = $this->options['id'] . '-container';
        }
    }

    /**
     * @inheritdoc
     */
    public function run(): string
    {
        $this->registerAssets();
        return Html::tag('div', '', $this->containerOptions);
    }

    /**
     * Render a HTML textarea tag.
     *
     * This will call [[Html::activeTextarea()]] if the input widget is [[hasModel()|tied to a model]],
     * or [[Html::textarea()]] if not.
     *
     * @return string the HTML of the textarea field.
     * @see Html::activeTextarea()
     * @see Html::textarea()
     */
    protected function renderTextareaHtml(): string
    {
        if ($this->hasModel()) {
            return Html::activeTextarea($this->model, $this->attribute, $this->options);
        }
        return Html::textarea($this->name, $this->value, $this->options);
    }

    /**
     * Register all needed asset bundles and scripts
     */
    protected function registerAssets(): void
    {
        JediAsset::register($this->view);

        // Setup variables for later use
        $containerId = $this->containerOptions['id'];
        $inputId = $this->hasModel() ? Html::getInputId($this->model, $this->attribute) : $this->options['id'];
        $inputName = $this->hasModel() ? Html::getInputName($this->model, $this->attribute) : $this->name;
        $id = Inflector::slug($inputId, '');

        // Escape json for config if needed
        $schema = Json::htmlEncode($this->_schema);
        $pluginOptions = Json::htmlEncode($this->pluginOptions);

        $refParser = $this->pluginOptions['refParser'] ?? null;

        // Init editor
        $this->view->registerJs(<<<JS
const initEditor$id = async () => {
    const schema = $schema
    const refParser = $refParser
    
    if (refParser) {
        await refParser.dereference(schema)
    }
    
    const defaultOptions = {
        container: document.getElementById('$containerId'),
        schema: schema,
        hiddenInputAttributes: {
            'name': '$inputName',
            'id': '$inputId'
        }
    }
    
    const customOptions = $pluginOptions
    
    const editorOptions = deepMerge(defaultOptions, customOptions)
    
    const editor = new Jedi.Create(editorOptions) 
    
    if (editor) {
        window['$inputId'] = editor
    }
    // Deep merge object to merge instead of overwrite
    function deepMerge(obj1, obj2) {
    const result = { ...obj1 }; // Start with a shallow copy of obj1
    for (const key in obj2) {
        if (obj2[key] instanceof Object && key in result) {
            result[key] = deepMerge(result[key], obj2[key]); // Recursively merge
        } else {
            result[key] = obj2[key]; // Overwrite if not an object
        }
    }
    return result;
   }
}

initEditor$id()
JS
        );
    }


    /**
     * Convert schema to json array if given as string or stdClass
     */
    public function setSchema(array|stdClass|string $schema): void
    {
        if ($schema instanceof stdClass) {
            $schema = Json::encode($schema);
            // Now that the value is a string, it is converted to an array in the next condition.
        }

        if (is_string($schema)) {
            $schema = Json::decode($schema);
        }

        $this->_schema = $schema;
    }

    /**
     * Reset all plugin options that should not be overwritten
     */
    protected function ensurePluginOptions(): void
    {
        unset($this->pluginOptions['container']);
        unset($this->pluginOptions['hiddenInputAttributes']['name']);
        unset($this->pluginOptions['hiddenInputAttributes']['id']);

        // Set default theme if not set
        if (!isset($this->pluginOptions['theme'])) {
            $this->pluginOptions['theme'] = new JsExpression('new Jedi.Theme()');
        }

        // Set default ref parser if not set
        if (!isset($this->pluginOptions['refParser'])) {
            $this->pluginOptions['refParser'] = new JsExpression('new Jedi.RefParser()');
        }

        // Set default value
        if ($this->hasModel()) {
            $data = $this->model->{$this->attribute};
        } else {
            $data = $this->value;
        }

        // Check if value is valid json. json_decode throws an error which we can "catch" with the json_last_error function
        json_decode($data);
        if (json_last_error() === JSON_ERROR_NONE) {
            $this->pluginOptions['data'] = new JsExpression($data);
        } else {
            Yii::warning('Data is not a valid JSON.');
        }
    }
}
