/* global Shopware */

import './extension/sw-order';
import './page/trustpayments-order-detail';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';
import frFR from './snippet/fr-FR.json';
import itIT from './snippet/it-IT.json';

const {Module} = Shopware;

Module.register('trustpayments-order', {
	type: 'plugin',
	name: 'TrustPayments',
	title: 'trustpayments-order.general.title',
	description: 'trustpayments-order.general.descriptionTextModule',
	version: '1.0.0',
	targetVersion: '1.0.0',
	color: '#2b52ff',

	snippets: {
		'de-DE': deDE,
		'en-GB': enGB,
		'fr-FR': frFR,
		'it-IT': itIT
	},

	routeMiddleware(next, currentRoute) {
		if (currentRoute.name === 'sw.order.detail') {
			currentRoute.children.push({
				component: 'trustpayments-order-detail',
				name: 'trustpayments.order.detail',
				isChildren: true,
				path: '/sw/order/trustpayments/detail/:id'
			});
		}
		next(currentRoute);
	}
});
