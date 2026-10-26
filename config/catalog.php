<?php

return [
    'products' => ['table' => 'products', 'title' => 'Products', 'search' => ['product_name', 'barcode', 'category'], 'columns' => ['product_name' => 'Product', 'barcode' => 'Barcode', 'category' => 'Category', 'price' => 'Price', 'stock' => 'On hand', 'is_active' => 'Active'], 'fields' => ['product_name' => 'Product name', 'barcode' => 'Barcode', 'category' => 'Category', 'price' => 'Selling price', 'cost_price' => 'Cost price', 'supplier_id' => 'Supplier', 'is_active' => 'Active']],
    'categories' => ['table' => 'categories', 'title' => 'Categories', 'search' => ['category_name'], 'columns' => ['category_name' => 'Category'], 'fields' => ['category_name' => 'Category name']],
    'suppliers' => ['table' => 'suppliers', 'title' => 'Suppliers', 'search' => ['company_name', 'contact_person', 'email'], 'columns' => ['company_name' => 'Company', 'contact_person' => 'Contact person', 'contact_number' => 'Phone', 'email' => 'Email', 'status' => 'Status'], 'fields' => ['company_name' => 'Company name', 'contact_person' => 'Contact person', 'contact_number' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'payment_terms_days' => 'Payment terms in days', 'status' => 'Status', 'notes' => 'Notes']],
];
