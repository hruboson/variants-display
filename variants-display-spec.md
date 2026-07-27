# Overview

This plugin will change the way the variations are displayed on a product detail. It should be user friendly and responsive on mobile. The primary goal is to make a variant chooser for products where there are 10-150 variants (mostly just different colors) with the colors visible even before choosing the variant. The colors will actually be the pictures of the woocommerce variants.

# Modes

There will be three different modes, with mode number one being default for all products while the plugin is active. In administration user can change the mode for each product separately.

1. Default mode
    - in this mode the products are displayed as medium sized tiles and is made for products with very few variants (max 4-5). Default mode.
2. Grid mode
    - in this mode variants are displayed in a grid, on mobile it should bring up a modal with the variants and be scrollable, this mode is for products with about 10-20 variants.
3. Finer grid mode
    - in this mode variants are not displayed directly on the product page upon visit but only show up when user clicks button "Vybrat variantu". In that case a modal that dims the background appears with fine grid. This mode is meant for products with 25-150 variants.

# Frontend

The variant chooser should be at the same location where the default woocommerce picker is located. The variants should be shown as tiles (in default mode) and as squares (size depends on grid or finer grid mode). The content of the tile will always be a picture of the variation (from woocommerce), variation name or description. User should also be able to select bulk mode (checkbox), where he can click on + and - next to the product and add many units at once (after clicking on add to cart). The design should be modern and use the theme colors (Blocksy theme).

# Administration

In the administration the admin/authorized user (using wordpress native roles) will be able to toggle the modes of the display for each product separately.
