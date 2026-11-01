package id.cheerychic.wmsscan

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.data.Stages
import id.cheerychic.wmsscan.ui.HomeScreen
import id.cheerychic.wmsscan.ui.KemasScreen
import id.cheerychic.wmsscan.ui.LoginScreen
import id.cheerychic.wmsscan.ui.OrderDetailScreen
import id.cheerychic.wmsscan.ui.OrdersScreen
import id.cheerychic.wmsscan.ui.SerahTerimaScreen
import id.cheerychic.wmsscan.ui.components.Tab
import id.cheerychic.wmsscan.ui.theme.WmsTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContent {
            WmsTheme { AppRoot() }
        }
    }
}

@Composable
private fun AppRoot() {
    val user by Session.user.collectAsStateWithLifecycle()

    if (user == null) {
        LoginScreen()
        return
    }

    val nav = rememberNavController()
    val openTab: (Tab) -> Unit = { tab -> nav.openTab(tab) }

    NavHost(navController = nav, startDestination = "home") {
        composable("home") {
            HomeScreen(user = user!!, onTab = openTab)
        }
        composable("kemas") { KemasScreen(onBack = { nav.popBackStack() }) }
        composable("serah") {
            SerahTerimaScreen(
                onBack = { nav.popBackStack() },
                onQueue = { nav.navigate("orders/${Stages.SUDAH}") },
            )
        }
        composable(
            "orders/{stage}",
            arguments = listOf(navArgument("stage") { type = NavType.StringType }),
        ) { entry ->
            OrdersScreen(
                initialStage = entry.arguments?.getString("stage") ?: Stages.BELUM,
                onTab = openTab,
                onOpen = { id -> nav.navigate("order/$id") },
            )
        }
        composable(
            "order/{id}",
            arguments = listOf(navArgument("id") { type = NavType.IntType }),
        ) { entry ->
            OrderDetailScreen(
                id = entry.arguments?.getInt("id") ?: 0,
                onBack = { nav.popBackStack() },
            )
        }
    }
}

private fun NavHostController.openTab(tab: Tab) {
    val user = Session.user.value ?: return
    when (tab) {
        Tab.Home -> popBackStack("home", inclusive = false)
        Tab.Kemas -> if (user.can.kemas) navigate("kemas")
        Tab.Serah -> if (user.can.serahTerima) navigate("serah")
        Tab.Pesanan -> if (user.can.pesanan) navigate("orders/${Stages.BELUM}") {
            popUpTo("home")
            launchSingleTop = true
        }
    }
}
