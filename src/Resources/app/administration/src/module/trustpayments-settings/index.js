/* global Shopware */

import './acl';
import './page/trustpayments-settings';
import './component/sw-trustpayments-credentials';
import './component/sw-trustpayments-options';
import './component/sw-trustpayments-settings-icon';
import './component/sw-trustpayments-storefront-options';

const {Module} = Shopware;

Module.register('trustpayments-settings', {
	type: 'plugin',
	name: 'TrustPayments',
	title: 'trustpayments-settings.general.descriptionTextModule',
	description: 'trustpayments-settings.general.descriptionTextModule',
	color: '#28d8ff',
	icon: 'default-action-settings',
	version: '1.0.0',
	targetVersion: '1.0.0',

	routes: {
		index: {
			component: 'trustpayments-settings',
			path: 'index',
			meta: {
				parentPath: 'sw.settings.index',
				privilege: 'trustpayments.viewer'
			}
		}
	},

	settingsItem: {
		group: 'plugins',
		to: 'trustpayments.settings.index',
		iconComponent: 'sw-trustpayments-settings-icon',
		backgroundEnabled: true,
		privilege: 'trustpayments.viewer'
	}

});
