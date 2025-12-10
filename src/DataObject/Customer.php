<?php declare( strict_types=1 );

namespace Tangible\DataObject;

abstract class Customer {

    /**
     * @var DataSet
     */
    protected DataSet $data;

    abstract public function get_storage();

    /**
     * Sets the dataset to be used by this singular object.
     *
     * @param DataSet $dataset the dataset to use.
     */
    public function set_dataset( DataSet $dataset ) {
        $this->data = $dataset;
        return $this;
    }

    /**
     * Returns the dataset used by this singular object.
     *
     * @return DataSet the used dataset.
     */
    public function get_dataset(): DataSet {
        return $this->data;
    }
}
