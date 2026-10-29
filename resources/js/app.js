import './bootstrap';
import './navigation';
import './enhance';

import Alpine from 'alpinejs';
import initHints from './hints';
import bundleRecipe from './bundle-recipe';
import cameraScanner from './camera-scanner';
import commandPalette from './command-palette';
import handoverStation from './handover-station';
import lineItems from './line-items';
import opnameCounter from './opname-counter';
import opnameStation from './opname-station';
import outboundBundles from './outbound-bundles';
import packingStation from './packing-station';
import productBulkEdit from './product-bulk-edit';
import resiLookup from './resi-lookup';
import returnStation from './return-station';
import documentScanner from './scanner';
import stockPicker from './stock-picker';
import variantBuilder from './variant-builder';

window.Alpine = Alpine;

// Didaftarkan sebelum start() agar komponen hasil swap AJAX ikut dikenali.
Alpine.data('bundleRecipe', bundleRecipe);
Alpine.data('cameraScanner', cameraScanner);
Alpine.data('commandPalette', commandPalette);
Alpine.data('handoverStation', handoverStation);
Alpine.data('lineItems', lineItems);
Alpine.data('opnameCounter', opnameCounter);
Alpine.data('opnameStation', opnameStation);
Alpine.data('outboundBundles', outboundBundles);
Alpine.data('packingStation', packingStation);
Alpine.data('productBulkEdit', productBulkEdit);
Alpine.data('resiLookup', resiLookup);
Alpine.data('returnStation', returnStation);
Alpine.data('documentScanner', documentScanner);
Alpine.data('stockPicker', stockPicker);
Alpine.data('variantBuilder', variantBuilder);

initHints();

Alpine.start();
