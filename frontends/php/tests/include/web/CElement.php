<?php
/*
** Zabbix
** Copyright (C) 2001-2018 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once 'vendor/autoload.php';

require_once dirname(__FILE__).'/CBaseElement.php';
require_once dirname(__FILE__).'/CElementQuery.php';

require_once dirname(__FILE__).'/IWaitable.php';
require_once dirname(__FILE__).'/WaitableTrait.php';
require_once dirname(__FILE__).'/CastableTrait.php';

/**
 * Generic web page element.
 */
class CElement extends CBaseElement implements IWaitable {

	/**
	 * Element can be used as waitable object to wait for element state changes.
	 */
	use WaitableTrait;

	/**
	 * Element can be used as castable object to cast element to specific type.
	 */
	use CastableTrait;

	/**
	 * Element selector.
	 *
	 * @var WebDriverBy
	 */
	protected $by;

	/**
	 * Parent element (if any).
	 * Parent element is never set for elements retrieved from element collection.
	 *
	 * @var CElement
	 */
	protected $parent;

	/**
	 * Initialize element.
	 *
	 * @param RemoteWebElement $element
	 * @param type $options
	 */
	public function __construct(RemoteWebElement $element, $options = []) {
		$this->setElement($element);
		$this->normalize();

		foreach ($options as $key => $value) {
			$this->$key = $value;
		}
	}

	/**
	 * Simplified selector for elements that can be located directly on page.
	 * @throws Exception
	 */
	public static function find() {
		throw new Exception('Element cannot be located without selector.');
	}

	/**
	 * Invalidate element state.
	 * This method should be overridden in order to reset cached objects that will be broken during reload operation.
	 * @see CBaseElement::reload
	 */
	public function invalidate() {
		// Code is not missing here.
	}

	/**
	 * Reload stalled element if reload is possible.
	 *
	 * @return $this
	 *
	 * @throws Exception
	 */
	public function reload() {
		if ($this->by === null) {
			throw new Exception('Cannot reload stalled element selected as a part of multi-element selection.');
		}

		if ($this->parent !== null) {
			$this->parent->reload();
		}

		$this->invalidate();
		$query = new CElementQuery($this->by);
		if ($this->parent !== null) {
			$query->setContext($this->parent);
		}

		$this->setElement($query->one());
		$this->normalize();

		return $this;
	}

	/**
	 * Set new base element.
	 *
	 * @param RemoteWebElement $element    element to be set
	 */
	protected function setElement(RemoteWebElement $element) {
		$this->executor = $element->executor;
		$this->id = $element->id;
		$this->fileDetector = $element->fileDetector;
	}

	/**
	 * Perform element selector normalization.
	 * This method can be overridden to check if element is selected properly.
	 */
	protected function normalize() {
		// Code is not missing here.
	}

	/**
	 * Get parent selection query.
	 *
	 * @param mixed  $type     selector type (method) or selector
	 * @param string $locator  locator part of selector
	 *
	 * @return CElementQuery
	 */
	public function parents($type, $locator = null) {
		return $this->query('xpath:./ancestor::'.CXPathHelper::fromSelector($type, $locator));
	}

	/**
	 * Get element query with current element context.
	 *
	 * @param mixed  $type     selector type (method) or selector
	 * @param string $locator  locator part of selector
	 *
	 * @return CElementQuery
	 */
	public function query($type, $locator = null) {
		$query = new CElementQuery($type, $locator);
		$query->setContext($this);

		return $query;
	}

	/**
	 * Dispatch HTML event to an element.
	 *
	 * @param string $event    event type
	 *
	 * @return $this
	 */
	public function fireEvent($event = 'change') {
		$driver = CElementQuery::getDriver();
		$driver->executeScript('arguments[0].dispatchEvent(new Event(arguments[1]));', [$this, $event]);

		return $this;
	}

	/**
	 * Highlight element by setting orange border around it.
	 * This method should be used for test debugging purposes only.
	 *
	 * @return $this
	 */
	public function highlight() {
		$driver = CElementQuery::getDriver();
		$driver->executeScript('arguments[0].style.border="3px solid #ff9800";', [$this]);

		return $this;
	}

	/**
	 * Get instance of specified element class from current element.
	 *
	 * @param string $class      class to be casted to
	 * @param array  $options    additional options passed to object
	 *
	 * @return CElement
	 */
	public function cast($class, $options = []) {
		return new $class($this, array_merge($options, ['parent' => $this->parent, 'by' => $this->by]));
	}

	/**
	 * Check if element is clickable.
	 *
	 * @return boolean
	 */
	public function isClickable() {
		return $this->isDisplayed() && $this->isEnabled();
	}

	/**
	 * @inheritdoc
	 */
	public function getClickableCondition() {
		$target = $this;

		return function () use ($target) {
			return $target->isClickable();
		};
	}

	/**
	 * @inheritdoc
	 */
	public function getPresentCondition() {
		$target = $this;

		return function () use ($target) {
			return !$target->isStalled();
		};
	}

	/**
	 * @inheritdoc
	 */
	public function getVisibleCondition() {
		$target = $this;

		return function () use ($target) {
			return $target->isVisible();
		};
	}

	/**
	 * @inheritdoc
	 */
	public function getTextPresentCondition($text) {
		$target = $this;

		return function () use ($target, $text) {
			return (strpos($target->getText(), $text) !== false);
		};
	}

	/**
	 * @inheritdoc
	 */
	public function getAttributesPresentCondition($attributes) {
		$target = $this;

		return function () use ($target, $attributes) {
			foreach ($attributes as $key => $value) {
				if (is_numeric($key) && $target->getAttribute($value) === null) {
					return false;
				}
				elseif ($target->getAttribute($key) !== $value) {
					return false;
				}
			}

			return true;
		};
	}

	/**
	 * @inheritdoc
	 */
	public function getReadyCondition() {
		return $this->getClickableCondition();
	}

	/**
	 * Wait until element changes it's state from stalled to normal.
	 *
	 * @return $this
	 * @throws Exception
	 */
	public function waitUntilReloaded() {
		if ($this->by === null) {
			throw new Exception('Cannot wait for element reload on element selected in multi-element query.');
		}

		$element = $this;
		CElementQuery::wait()->until(function () use ($element) {
				if ($element->isStalled()) {
					$element->reload();

					return !$element->isStalled();
				}

				return null;
			}
		);

		return $this;
	}

	/**
	 * Wait until element is selected.
	 *
	 * @return $this
	 */
	public function waitUntilSelected() {
		CElementQuery::wait()->until(WebDriverExpectedCondition::elementToBeSelected($this));

		return $this;
	}
}
